<?php

namespace Drupal\kb_chat\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\kb_chat\Drive\DriveSync;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists and reads FAITH's priority documents for the kb chat.
 *
 * The documents are chosen in the Drive settings (priority_documents): for
 * example the production schedules and the management team's meeting notes.
 * They're read from Drive as they are now, so a schedule changed this morning
 * is current, and only documents the user may see are listed or read.
 */
#[FunctionCall(
  id: 'kb_chat:priority_documents',
  function_name: 'kb_chat_priority_documents',
  name: 'Read priority documents',
  description: 'FAITH\'s priority documents: the most important current documents, such as production schedules and management meeting notes, read live from Google Drive. They take precedence over other sources on the same subject. Call it with no arguments to see which documents there are and what each is for; then call it with a document name to read it. For a long document, pass keywords in search to get only the matching lines with their context. Check it first whenever a question might be covered by one of these documents (schedules, deadlines, dates, decisions).',
  group: 'information_tools',
  context_definitions: [
    'document' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Document'),
      description: new TranslatableMarkup('Optional. The name, or part of the name, of the priority document to read. Leave empty to list them.'),
      required: FALSE,
    ),
    'search' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Search'),
      description: new TranslatableMarkup('Optional. Keywords: only return the lines of the document that contain any of them, with the lines around each. Use it for long documents, e.g. a publication or client name in a production schedule.'),
      required: FALSE,
    ),
  ],
)]
class PriorityDocumentsTool extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * Most characters of a document returned without a search.
   */
  const MAX_CHARS = 40000;

  /**
   * Most characters of search matches returned.
   */
  const MAX_MATCH_CHARS = 20000;

  /**
   * Lines of context around each match.
   */
  const CONTEXT = 2;

  protected EntityTypeManagerInterface $entityTypeManager;

  protected DriveSync $driveSync;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    $instance = new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ai.context_definition_normalizer'),
    );
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->driveSync = $container->get(DriveSync::class);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $notes = $this->driveSync->priorityDocuments();
    $documents = $this->documents($notes);
    if (!$documents) {
      $this->setOutput('There are no priority documents available to this user.');
      return;
    }

    $name = trim((string) ($this->getContextValue('document') ?? ''));
    if ($name === '') {
      $output = "Priority documents (they take precedence over other sources on the same subject). Read one by passing its name as document.\n\n";
      foreach ($documents as $document) {
        $output .= $this->describe($document, $notes) . "\n";
      }
      $this->setOutput($output);
      return;
    }

    $matches = array_filter($documents, fn($document) => str_contains(mb_strtolower($document->label()), mb_strtolower($name)));
    if (count($matches) !== 1) {
      $output = ($matches ? "Several priority documents match \"$name\"; use a more exact name:\n\n" : "No priority document matches \"$name\". These are available:\n\n");
      foreach ($matches ?: $documents as $document) {
        $output .= $this->describe($document, $notes) . "\n";
      }
      $this->setOutput($output);
      return;
    }

    /** @var \Drupal\kb_chat\Entity\KbChatDocument $document */
    $document = reset($matches);
    $current = $this->driveSync->currentText($document);
    $text = $current['text'];
    $search = trim((string) ($this->getContextValue('search') ?? ''));

    $output = $this->describe($document, $notes)
      . ($current['live']
        ? 'Read from Google Drive at ' . date('M j, g:ia', $current['as_of']) . ".\n"
        : 'Google Drive could not be read; this is the copy synced at ' . date('M j, g:ia', $current['as_of']) . ", which may be out of date.\n")
      . "Cite it as a Markdown link to its URL.\n\n";
    if ($search !== '') {
      $excerpt = $this->matchingLines($text, $search);
      $output .= $excerpt === ''
        ? "No lines contain \"$search\". Try other keywords, or read the document without a search."
        : "Lines containing \"$search\" (with " . self::CONTEXT . " lines around each; \"...\" marks a gap):\n```\n$excerpt\n```";
    }
    else {
      $output .= "```\n" . mb_substr($text, 0, self::MAX_CHARS) . "\n```";
      if (mb_strlen($text) > self::MAX_CHARS) {
        $output .= "\nOnly the first " . number_format(self::MAX_CHARS) . ' of ' . number_format(mb_strlen($text)) . ' characters are shown. Use search to find the rest.';
      }
    }
    $this->setOutput($output);
  }

  /**
   * The synced priority documents the current user may view, in list order.
   *
   * @return \Drupal\kb_chat\Entity\KbChatDocument[]
   *   Keyed by file ID.
   */
  protected function documents(array $notes): array {
    if (!$notes) {
      return [];
    }
    $loaded = $this->entityTypeManager->getStorage('kb_chat_document')->loadByProperties([
      'source' => DriveSync::SOURCE,
      'source_id' => array_keys($notes),
    ]);
    $documents = [];
    foreach ($loaded as $document) {
      if ($document->access('view')) {
        $documents[$document->get('source_id')->value] = $document;
      }
    }
    return array_filter(array_replace(array_fill_keys(array_keys($notes), NULL), $documents));
  }

  /**
   * A document's title, purpose, link and when it last changed.
   */
  protected function describe($document, array $notes): string {
    $lines = ['Title: ' . $document->label()];
    if ($note = $notes[$document->get('source_id')->value] ?? '') {
      $lines[] = "What it's for: $note";
    }
    $lines[] = 'URL: ' . $document->getSourceUrl();
    if ($modified = (int) $document->get('modified')->value) {
      $lines[] = 'Last changed (at the last sync): ' . date('M j, Y', $modified);
    }
    return implode("\n", $lines) . "\n";
  }

  /**
   * The lines containing any keyword, with context, gaps marked "...".
   *
   * In a spreadsheet ("Sheet: name" lines, then one row per line), each
   * match also keeps its sheet's name and first row, usually the column
   * headings, so the matching rows can be read.
   */
  protected function matchingLines(string $text, string $search): string {
    $words = array_filter(preg_split('/[\s,]+/u', mb_strtolower(str_replace('"', '', $search))), fn($word) => mb_strlen($word) >= 2);
    if (!$words) {
      return '';
    }
    $lines = preg_split('/\R/u', $text);
    $keep = [];
    $sheet = NULL;
    foreach ($lines as $i => $line) {
      if (str_starts_with($line, 'Sheet: ')) {
        $sheet = $i;
      }
      $lower = mb_strtolower($line);
      foreach ($words as $word) {
        if (str_contains($lower, $word)) {
          if ($sheet !== NULL) {
            $keep[$sheet] = TRUE;
            $keep[$sheet + 1] = TRUE;
          }
          for ($j = max(0, $i - self::CONTEXT); $j <= min(count($lines) - 1, $i + self::CONTEXT); $j++) {
            $keep[$j] = TRUE;
          }
          break;
        }
      }
    }
    ksort($keep);
    $excerpt = '';
    $previous = NULL;
    foreach (array_keys($keep) as $i) {
      if ($previous !== NULL && $i > $previous + 1) {
        $excerpt .= "...\n";
      }
      $excerpt .= $lines[$i] . "\n";
      $previous = $i;
      if (mb_strlen($excerpt) > self::MAX_MATCH_CHARS) {
        $excerpt .= "... (more matches; use more specific keywords)\n";
        break;
      }
    }
    return trim($excerpt);
  }

}
