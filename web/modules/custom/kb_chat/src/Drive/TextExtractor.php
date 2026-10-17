<?php

namespace Drupal\kb_chat\Drive;

use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;

/**
 * Turns Google Drive files into plain text for indexing.
 *
 * Google Docs and Slides are exported as text by Google; Sheets are exported
 * as .xlsx so every sheet is included. Word and Excel files are read from
 * their XML; PDFs use smalot/pdfparser (scanned PDFs have no text layer and
 * come out empty).
 */
class TextExtractor {

  const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

  const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

  /**
   * How each supported type is read: MIME type => [method, export type].
   *
   * An export type means Google converts the file first (Google's own
   * formats); otherwise the file is downloaded as it is.
   */
  const TYPES = [
    'application/vnd.google-apps.document' => ['plain', 'text/plain'],
    'application/vnd.google-apps.presentation' => ['plain', 'text/plain'],
    'application/vnd.google-apps.spreadsheet' => ['xlsx', self::XLSX],
    'application/pdf' => ['pdf', NULL],
    self::DOCX => ['docx', NULL],
    self::XLSX => ['xlsx', NULL],
    'text/plain' => ['plain', NULL],
    'text/csv' => ['plain', NULL],
    'text/markdown' => ['plain', NULL],
    'text/x-markdown' => ['plain', NULL],
  ];

  /**
   * The MIME types that are indexed.
   */
  public function mimeTypes(): array {
    return array_keys(self::TYPES);
  }

  /**
   * Whether a MIME type is indexed.
   */
  public function supports(string $mime_type): bool {
    return isset(self::TYPES[$mime_type]);
  }

  /**
   * Reads a file's text.
   *
   * @param \Drupal\kb_chat\Drive\GoogleDriveClient $drive
   *   A client for an account that can open the file.
   * @param array $file
   *   The Drive file: id and mimeType.
   * @param int $max_chars
   *   Text beyond this many characters is dropped.
   */
  public function extract(GoogleDriveClient $drive, array $file, int $max_chars): string {
    [, $export] = self::TYPES[$file['mimeType']];
    $content = $export ? $drive->export($file['id'], $export) : $drive->download($file['id']);
    return $this->fromContent($export ?? $file['mimeType'], $content, $max_chars);
  }

  /**
   * Turns file content of a supported type into plain text.
   *
   * @param string $mime_type
   *   The content's type: one of TYPES, or the exported type for Google's own
   *   formats (text/plain, or the Excel type for Sheets).
   * @param string $content
   *   The file's bytes.
   * @param int $max_chars
   *   Text beyond this many characters is dropped.
   */
  public function fromContent(string $mime_type, string $content, int $max_chars): string {
    $text = match (self::TYPES[$mime_type][0] ?? 'plain') {
      'pdf' => $this->pdf($content),
      'docx' => $this->docx($content),
      'xlsx' => $this->xlsx($content),
      default => $content,
    };
    // Normalize encoding and whitespace, then cap the length.
    $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    $text = preg_replace("/[ \t]+/u", ' ', $text);
    $text = trim(preg_replace("/\n{3,}/u", "\n\n", $text));
    return mb_substr($text, 0, $max_chars);
  }

  /**
   * The text of a PDF.
   */
  protected function pdf(string $content): string {
    if (!class_exists(Parser::class)) {
      throw new \RuntimeException('PDF support needs smalot/pdfparser: composer require smalot/pdfparser');
    }
    $config = new Config();
    // Image data is never needed for text and is the bulk of most PDFs' size.
    $config->setRetainImageContent(FALSE);
    // Cap the memory used to unpack any one stream.
    $config->setDecodeMemoryLimit(16 * 1024 * 1024);
    // Many "secured" PDFs only restrict printing or copying; their text is
    // still readable. Truly password-protected files fail as before.
    $config->setIgnoreEncryption(TRUE);
    return (new Parser([], $config))->parseContent($content)->getText();
  }

  /**
   * The text of a Word (.docx) file, one paragraph per line.
   */
  protected function docx(string $content): string {
    $xml = $this->zipEntries($content, ['word/document.xml'])['word/document.xml'] ?? '';
    $xml = str_replace(['</w:p>', '<w:tab/>', '<w:br/>'], ["\n", "\t", "\n"], $xml);
    return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
  }

  /**
   * The text of an Excel (.xlsx) file: each sheet, one row per line.
   */
  protected function xlsx(string $content): string {
    $entries = $this->zipEntries($content, NULL, '#^xl/(sharedStrings\.xml|workbook\.xml|worksheets/sheet\d+\.xml)$#');

    $strings = [];
    if (isset($entries['xl/sharedStrings.xml']) && ($shared = $this->xml($entries['xl/sharedStrings.xml']))) {
      foreach ($shared->si as $item) {
        // A shared string is either one <t> or several rich-text runs.
        $strings[] = isset($item->t) ? (string) $item->t : implode('', array_map('strval', iterator_to_array($item->xpath('.//*[local-name()="t"]'), FALSE)));
      }
    }
    $names = [];
    if (isset($entries['xl/workbook.xml']) && ($workbook = $this->xml($entries['xl/workbook.xml']))) {
      foreach ($workbook->sheets->sheet ?? [] as $sheet) {
        $names[] = (string) $sheet['name'];
      }
    }

    $sheets = array_filter(array_keys($entries), fn($name) => str_starts_with($name, 'xl/worksheets/'));
    natsort($sheets);
    $output = [];
    foreach (array_values($sheets) as $index => $name) {
      $sheet = $this->xml($entries[$name]);
      if (!$sheet) {
        continue;
      }
      $output[] = 'Sheet: ' . ($names[$index] ?? 'Sheet ' . ($index + 1));
      foreach ($sheet->sheetData->row ?? [] as $row) {
        $cells = [];
        foreach ($row->c as $cell) {
          $value = (string) ($cell->v ?? '');
          if ((string) $cell['t'] === 's') {
            $value = $strings[(int) $value] ?? '';
          }
          elseif ((string) $cell['t'] === 'inlineStr') {
            $value = (string) ($cell->is->t ?? '');
          }
          if ($value !== '') {
            $cells[] = $value;
          }
        }
        if ($cells) {
          $output[] = implode(' | ', $cells);
        }
      }
      $output[] = '';
    }
    return implode("\n", $output);
  }

  /**
   * Reads entries from a zip archive held in memory.
   *
   * @param string $content
   *   The zip file's bytes.
   * @param array|null $names
   *   Entry names to read, or NULL to match $pattern.
   * @param string|null $pattern
   *   A regular expression for entry names.
   */
  protected function zipEntries(string $content, ?array $names, ?string $pattern = NULL): array {
    $path = tempnam(sys_get_temp_dir(), 'kbchat');
    file_put_contents($path, $content);
    $zip = new \ZipArchive();
    $entries = [];
    try {
      if ($zip->open($path) !== TRUE) {
        throw new \RuntimeException('Not a valid Office file.');
      }
      for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (($names !== NULL && in_array($name, $names, TRUE)) || ($pattern && preg_match($pattern, $name))) {
          $entries[$name] = (string) $zip->getFromIndex($i);
        }
      }
      $zip->close();
    }
    finally {
      @unlink($path);
    }
    return $entries;
  }

  /**
   * Parses XML without network access, or returns NULL.
   */
  protected function xml(string $xml): ?\SimpleXMLElement {
    $element = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    return $element === FALSE ? NULL : $element;
  }

}
