<?php

namespace Drupal\upsun_dashboard;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Collects a snapshot of every Upsun project's production environment.
 *
 * The snapshot is kept in State so the dashboard page never calls the API.
 */
class DashboardCollector {

  const STATE_KEY = 'upsun_dashboard.snapshot';

  /**
   * Composer packages whose versions the dashboard shows.
   */
  const PACKAGES = ['drupal/core', 'drush/drush'];

  public function __construct(
    protected UpsunClient $client,
    protected StateInterface $state,
    protected TimeInterface $time,
    #[Autowire(service: 'logger.channel.upsun_dashboard')]
    protected LoggerInterface $logger,
  ) {}

  /**
   * Returns the stored snapshot.
   *
   * @return array
   *   With keys 'updated' (Unix time), 'projects' (keyed by project ID) and
   *   'billing' (see collectBilling()).
   */
  public function getSnapshot(): array {
    return $this->state->get(self::STATE_KEY, []) + [
      'updated' => 0,
      'projects' => [],
      'billing' => ['projects' => [], 'profiles' => []],
    ];
  }

  /**
   * Stores a new snapshot and clears the dashboard's render cache.
   */
  public function saveSnapshot(array $projects, array $billing): void {
    uasort($projects, fn($a, $b) => strcasecmp($a['title'], $b['title']));
    $this->state->set(self::STATE_KEY, [
      'updated' => $this->time->getCurrentTime(),
      'projects' => $projects,
      'billing' => $billing,
    ]);
    Cache::invalidateTags(['upsun_dashboard']);
  }

  /**
   * Whether the snapshot is older than the configured refresh interval.
   */
  public function isStale(int $interval): bool {
    return $this->time->getCurrentTime() - $this->getSnapshot()['updated'] >= $interval;
  }

  /**
   * Collects every project and the billing data, and stores the result.
   */
  public function refreshAll(): array {
    $previous = $this->getSnapshot()['projects'];
    $organizations = $this->listOrganizations();
    $projects = [];
    foreach ($this->listProjects($organizations) as $id => $stub) {
      $projects[$id] = $this->collectProject($stub, $previous[$id] ?? []);
    }
    $this->saveSnapshot($projects, $this->collectBilling($organizations));
    return $projects;
  }

  /**
   * Lists the organizations the token's user belongs to.
   */
  public function listOrganizations(): array {
    $me = $this->client->get('/users/me');
    return $this->client->getAll('/users/' . $me['id'] . '/organizations');
  }

  /**
   * Lists the projects in the given organizations.
   *
   * @return array
   *   Basic project details keyed by project ID.
   */
  public function listProjects(array $organizations): array {
    $projects = [];
    foreach ($organizations as $org) {
      foreach ($this->client->getAll('/organizations/' . $org['id'] . '/projects') as $project) {
        $projects[$project['id']] = [
          'id' => $project['id'],
          'title' => $project['title'] ?: $project['id'],
          'org' => $org['label'] ?? $org['name'],
          'org_type' => $org['type'] ?? '',
          'org_owner_id' => $org['owner_id'] ?? NULL,
          'region' => $project['region'] ?? '',
          'plan' => $project['plan'] ?? '',
          'status' => $project['status'] ?? '',
          'console_url' => $project['project_ui'] ?? '',
          'default_branch' => $project['default_branch'] ?? 'main',
        ];
      }
    }
    return $projects;
  }

  /**
   * Totals this month's and last month's invoices per project.
   *
   * Each organization bills through a billing profile. Its open (CURRENT)
   * invoice is the month so far: subscription items are priced for the whole
   * month and usage items keep accruing. Line items without a project, such
   * as support, user licenses and discounts, are kept per profile as
   * "unassigned".
   *
   * @return array
   *   - projects: Keyed by project ID, each with 'current' and 'previous'
   *     amounts in cents.
   *   - profiles: Keyed by billing profile ID, each with 'orgs' (labels),
   *     'error', and 'current' and 'previous' invoices: ['total',
   *     'unassigned', 'currency', 'start', 'end'], amounts in cents.
   */
  public function collectBilling(array $organizations): array {
    $profiles = [];
    foreach ($organizations as $org) {
      // Organizations the user doesn't own have no billing profile.
      if (!empty($org['billing_profile_id'])) {
        $profiles[$org['billing_profile_id']][] = $org['label'] ?? $org['name'];
      }
    }

    $billing = ['projects' => [], 'profiles' => []];
    foreach ($profiles as $profile_id => $labels) {
      $profile = ['orgs' => $labels, 'error' => NULL, 'current' => NULL, 'previous' => NULL];
      $base = '/billing/profiles/' . $profile_id . '/invoices';
      try {
        $current = $previous = NULL;
        foreach ($this->client->getAll($base) as $invoice) {
          if (($invoice['type'] ?? 'invoice') !== 'invoice') {
            continue;
          }
          if ($invoice['status'] === 'CURRENT') {
            $current ??= $invoice;
          }
          elseif (!$previous || $invoice['invoice_start_date'] > $previous['invoice_start_date']) {
            $previous = $invoice;
          }
        }

        foreach (['current' => $current, 'previous' => $previous] as $period => $invoice) {
          if (!$invoice) {
            continue;
          }
          $profile[$period] = [
            'total' => (int) $invoice['total']['amount'],
            'unassigned' => 0,
            'currency' => $invoice['total']['currency'] ?? 'USD',
            'start' => $invoice['invoice_start_date'],
            'end' => $invoice['invoice_end_date'],
          ];
          foreach ($this->client->getAll($base . '/' . $invoice['id'] . '/details') as $item) {
            $amount = (int) ($item['total']['amount'] ?? 0);
            if (!empty($item['project_id'])) {
              $billing['projects'][$item['project_id']][$period] = ($billing['projects'][$item['project_id']][$period] ?? 0) + $amount;
            }
            else {
              $profile[$period]['unassigned'] += $amount;
            }
          }
        }
      }
      catch (\Throwable $e) {
        $profile['error'] = $this->errorMessage($e);
        $this->logger->warning('Could not collect billing for @orgs: @message', [
          '@orgs' => implode(', ', $labels),
          '@message' => $profile['error'],
        ]);
      }
      $billing['profiles'][$profile_id] = $profile;
    }
    return $billing;
  }

  /**
   * Collects one project, never throwing.
   *
   * @param array $stub
   *   The project details from listProjects().
   * @param array $previous
   *   The project's row from the last snapshot, used to skip re-reading
   *   composer.lock when the commit hasn't changed, and as a fallback when
   *   the API call fails.
   */
  public function collectProject(array $stub, array $previous = []): array {
    try {
      return $this->doCollectProject($stub, $previous);
    }
    catch (\Throwable $e) {
      $message = $this->errorMessage($e);
      $this->logger->warning('Could not collect @project: @message', [
        '@project' => $stub['title'],
        '@message' => $message,
      ]);
      // Keep showing the last good data, flagged as out of date.
      return ['error' => $message, 'stale' => (bool) $previous] + $stub + $previous;
    }
  }

  /**
   * Collects one project.
   */
  protected function doCollectProject(array $stub, array $previous): array {
    $row = $stub + [
      'error' => NULL,
      'stale' => FALSE,
      'warnings' => [],
      'collected' => $this->time->getCurrentTime(),
    ];
    $base = '/projects/' . $stub['id'];

    $environments = $this->client->get($base . '/environments');
    $environment = NULL;
    foreach ($environments as $candidate) {
      if (($candidate['type'] ?? '') === 'production') {
        $environment = $candidate;
        break;
      }
      if (($candidate['id'] ?? '') === $stub['default_branch']) {
        $environment = $candidate;
      }
    }
    if (!$environment) {
      $row['error'] = 'No production environment.';
      return $row;
    }

    $env_path = $base . '/environments/' . rawurlencode($environment['id']);
    $row['environment'] = $environment['name'];
    $row['environment_status'] = $environment['status'] ?? '';
    $row['commit'] = $environment['head_commit'] ?? '';

    // Runtimes and services from the current deployment.
    $deployment = $this->client->get($env_path . '/deployments/current');
    $row['apps'] = [];
    foreach ($deployment['webapps'] ?? [] as $name => $app) {
      $row['apps'][$name] = $this->parseType($app['type'] ?? '');
    }
    $row['services'] = [];
    foreach ($deployment['services'] ?? [] as $name => $service) {
      $row['services'][$name] = $this->parseType($service['type'] ?? '');
    }
    $row['url'] = $this->primaryUrl($deployment['routes'] ?? []);

    // Package versions from composer.lock. Only re-read on a new commit.
    if ($row['commit'] && ($previous['commit'] ?? NULL) === $row['commit'] && array_key_exists('packages', $previous)) {
      $row['packages'] = $previous['packages'];
      $row['commit_date'] = $previous['commit_date'] ?? NULL;
    }
    elseif ($row['commit']) {
      try {
        $commit = $this->client->get($base . '/git/commits/' . $row['commit']);
        $row['commit_date'] = $commit['committer']['date'] ?? NULL;
        $row['packages'] = $this->readPackages($base, $commit['tree']);
      }
      catch (\Throwable $e) {
        $row['warnings'][] = 'composer.lock: ' . $this->errorMessage($e);
      }
    }

    // Recent CPU, memory and disk usage for each container.
    try {
      $row['usage'] = $this->readUsage($env_path, array_keys($row['apps'] + $row['services']));
    }
    catch (\Throwable $e) {
      $row['warnings'][] = 'Metrics: ' . $this->errorMessage($e);
    }

    try {
      $row['domains'] = $this->readDomains($base);
    }
    catch (\Throwable $e) {
      $row['warnings'][] = 'Domains: ' . $this->errorMessage($e);
    }

    try {
      $row['users'] = $this->readUsers($base, $stub['org_owner_id'] ?? NULL);
    }
    catch (\Throwable $e) {
      $row['warnings'][] = 'Users: ' . $this->errorMessage($e);
    }

    return $row;
  }

  /**
   * Reads the project's domains and their certificates.
   */
  protected function readDomains(string $base): array {
    $response = $this->client->get($base . '/domains');
    $domains = [];
    foreach ($response['items'] ?? $response as $domain) {
      // "countryName=US, organizationName=Let's Encrypt, commonName=YE1"
      preg_match('/organizationName=([^,]+)/', $domain['ssl']['ca'] ?? '', $issuer);
      $domains[] = [
        'name' => $domain['name'],
        'type' => $domain['type'] ?? '',
        'ssl_expires' => !empty($domain['ssl']['has_certificate']) ? ($domain['ssl']['expires_on'] ?? NULL) : NULL,
        'ssl_issuer' => $issuer[1] ?? NULL,
      ];
    }
    usort($domains, fn($a, $b) => strcmp($a['name'], $b['name']));
    return $domains;
  }

  /**
   * Reads the users who have access to the project.
   *
   * The access list only has user IDs. Each page links to a signed
   * ref:users URL that returns those users' names, emails and MFA status.
   */
  protected function readUsers(string $base, ?string $owner_id): array {
    $access = [];
    $people = [];
    $page = $this->client->get($base . '/user-access', ['page' => ['size' => 200]]);
    while (TRUE) {
      $access = array_merge($access, $page['items'] ?? []);
      foreach ($page['_links'] ?? [] as $key => $link) {
        if (str_starts_with($key, 'ref:users:')) {
          $people += $this->client->get($link['href']);
        }
      }
      if (empty($page['_links']['next']['href'])) {
        break;
      }
      $page = $this->client->get($page['_links']['next']['href']);
    }

    $users = [];
    foreach ($access as $grant) {
      $person = $people[$grant['user_id']] ?? [];
      $permissions = $grant['permissions'] ?? [];
      // "admin" or "viewer" for the project; "production:admin" and so on
      // for each environment type.
      $role = current(array_filter($permissions, fn($permission) => !str_contains($permission, ':'))) ?: '';
      $users[] = [
        'name' => trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? '')) ?: ($person['username'] ?? $grant['user_id']),
        'email' => $person['email'] ?? '',
        'role' => $role,
        'environment_roles' => $role === 'admin' ? [] : array_values(array_filter($permissions, fn($permission) => str_contains($permission, ':'))),
        'owner' => $grant['user_id'] === $owner_id,
        'mfa' => !empty($person['mfa_enabled']),
        'sso' => !empty($person['sso_enabled']),
        'granted' => $grant['granted_at'] ?? NULL,
      ];
    }
    // Owner first, then admins, then everyone else by name.
    usort($users, fn($a, $b) => [$b['owner'], $a['role'] !== 'admin', $a['name']] <=> [$a['owner'], $b['role'] !== 'admin', $b['name']]);
    return $users;
  }

  /**
   * Reads package versions from composer.lock at the root of a git tree.
   *
   * @return array|null
   *   Versions keyed by package name, or NULL if there is no composer.lock.
   */
  protected function readPackages(string $base, string $tree_sha): ?array {
    $tree = $this->client->get($base . '/git/trees/' . $tree_sha);
    $blob_sha = NULL;
    foreach ($tree['tree'] ?? [] as $entry) {
      if ($entry['path'] === 'composer.lock' && $entry['type'] === 'blob') {
        $blob_sha = $entry['sha'];
      }
    }
    if (!$blob_sha) {
      return NULL;
    }

    $blob = $this->client->get($base . '/git/blobs/' . $blob_sha);
    $content = ($blob['encoding'] ?? '') === 'base64' ? base64_decode($blob['content']) : $blob['content'];
    $lock = json_decode($content, TRUE) ?? [];

    $versions = [];
    foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
      if (in_array($package['name'], self::PACKAGES, TRUE)) {
        $versions[$package['name']] = ltrim($package['version'], 'v');
      }
    }
    return $versions;
  }

  /**
   * Reads the last hour's average usage and the limits for each container.
   *
   * @return array
   *   Keyed by container name, each with cpu, memory and disk entries of
   *   ['used' => float, 'limit' => float]. CPU is in cores, the rest in bytes.
   */
  protected function readUsage(string $env_path, array $containers): array {
    $now = $this->time->getCurrentTime();
    $metrics = $this->client->get($env_path . '/observability/resources/overview', [
      'from' => $now - 3600,
      'to' => $now,
      'grain' => 3600,
      'types' => ['cpu', 'memory', 'disk'],
      'aggs' => ['avg'],
    ]);

    // Use the most recent bucket that has data.
    $services = [];
    foreach ($metrics['data'] ?? [] as $bucket) {
      if (!empty($bucket['services'])) {
        $services = $bucket['services'];
      }
    }

    $usage = [];
    foreach ($containers as $name) {
      if (!isset($services[$name])) {
        continue;
      }
      $metric = $services[$name];
      $disk = ['used' => 0, 'limit' => 0];
      foreach ($metric['mountpoints'] ?? [] as $mount => $values) {
        // Skip the root filesystem and /tmp; only persistent disk matters.
        if ($mount === '' || $mount === '/tmp') {
          continue;
        }
        $disk['used'] += $values['disk_used']['avg'] ?? 0;
        $disk['limit'] += $values['disk_limit']['max'] ?? 0;
      }
      $usage[$name] = [
        'cpu' => [
          'used' => $metric['cpu_used']['avg'] ?? NULL,
          'limit' => $metric['cpu_limit']['max'] ?? NULL,
        ],
        'memory' => [
          'used' => $metric['memory_used']['avg'] ?? NULL,
          'limit' => $metric['memory_limit']['max'] ?? NULL,
        ],
        'disk' => $disk['limit'] ? $disk : NULL,
      ];
    }
    return $usage;
  }

  /**
   * Splits a type such as "php:8.4:790" into its runtime and version.
   */
  protected function parseType(string $type): array {
    $parts = explode(':', $type);
    return [
      'runtime' => $parts[0],
      'version' => $parts[1] ?? '',
    ];
  }

  /**
   * Returns the primary route URL, preferring a custom domain.
   */
  protected function primaryUrl(array $routes): ?string {
    $primary = [];
    foreach ($routes as $url => $route) {
      if (!empty($route['primary']) && ($route['type'] ?? '') === 'upstream') {
        $primary[] = $url;
      }
    }
    foreach ($primary as $url) {
      if (!preg_match('/\.(platformsh\.site|upsun\.app)\//', $url)) {
        return $url;
      }
    }
    return $primary[0] ?? NULL;
  }

  /**
   * Turns an exception into a short message for the dashboard.
   */
  protected function errorMessage(\Throwable $e): string {
    if ($e instanceof RequestException && $e->hasResponse()) {
      $response = $e->getResponse();
      return 'HTTP ' . $response->getStatusCode() . ' ' . $response->getReasonPhrase();
    }
    return $e->getMessage();
  }

}
