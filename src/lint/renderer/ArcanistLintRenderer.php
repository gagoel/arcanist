<?php

/*
 * Copyright 2011 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * Shows lint messages to the user.
 *
 * @group lint
 */
class ArcanistLintRenderer {
  public function renderLintResult(ArcanistLintResult $result) {
    $messages = $result->getMessages();
    $path = $result->getPath();

    $lines = explode("\n", $result->getData());

    $text = array();
    $text[] = phutil_console_format('**>>>** Lint for __%s__:', $path);
    $text[] = null;
    foreach ($messages as $message) {
      if ($message->isError()) {
        $color = 'red';
      } else {
        $color = 'yellow';
      }

      $severity = ArcanistLintSeverity::getStringForSeverity(
        $message->getSeverity());
      $code = $message->getCode();
      $name = $message->getName();
      $description = phutil_console_wrap($message->getDescription(), 4);

      $text[] = phutil_console_format(
        "  **<bg:{$color}> %s </bg>** (%s) __%s__\n".
        "    %s\n",
        $severity,
        $code,
        $name,
        $description);

      if ($message->hasFileContext()) {
        $text[] = $this->renderContext($message, $lines);
      }
    }
    $text[] = null;
    $text[] = null;

    return implode("\n", $text);
  }

  protected function renderContext(
    ArcanistLintMessage $message,
    array $line_data) {

    $lines_of_context = 3;
    $out = array();

    $num_lines = count($line_data);
     // make line numbers line up with array indexes
    array_unshift($line_data, '');

    $line_num = min($message->getLine(), $num_lines);
    $line_num = max(1, $line_num);

    // Print out preceding context before the impacted region.
    $cursor = max(1, $line_num - $lines_of_context);
    for (; $cursor < $line_num; $cursor++) {
      $out[] = $this->renderLine($cursor, $line_data[$cursor]);
    }

    $text = $message->getOriginalText();
    // Refine original and replacement text to eliminate start and end in common
    if ($message->isPatchable()) {
      $start = $message->getChar() - 1;
      $patch = $message->getReplacementText();
      $text_strlen = strlen($text);
      $patch_strlen = strlen($patch);
      $min_length = min($text_strlen, $patch_strlen);

      $same_at_front = 0;
      for ($ii = 0; $ii < $min_length; $ii++) {
        if ($text[$ii] !== $patch[$ii]) {
          break;
        }
        $same_at_front++;
        $start++;
        if ($text[$ii] == "\n") {
          $out[] = $this->renderLine($cursor, $line_data[$cursor]);
          $cursor++;
          $start = 0;
          $line_num++;
        }
      }
      // deal with shorter string '     ' longer string '     a     '
      $min_length -= $same_at_front;

      // And check the end of the string
      $same_at_end = 0;
      for ($ii = 1; $ii <= $min_length; $ii++) {
        if ($text[$text_strlen - $ii] !== $patch[$patch_strlen - $ii]) {
          break;
        }
        $same_at_end++;
      }

      $text = substr(
        $text,
        $same_at_front,
        $text_strlen - $same_at_end - $same_at_front
      );
      $patch = substr(
        $patch,
        $same_at_front,
        $patch_strlen - $same_at_end - $same_at_front
      );
    }
    // Print out the impacted region itself.
    $diff = $message->isPatchable() ? '-' : null;

    $text_lines = explode("\n", $text);
    $text_length = count($text_lines);

    for (; $cursor < $line_num + $text_length; $cursor++) {
      $chevron = ($cursor == $line_num);
      // We may not have any data if, e.g., the old file does not exist.
      $data = idx($line_data, $cursor, null);

      // Highlight the problem substring.
      $text_line = $text_lines[$cursor - $line_num];
      if (strlen($text_line)) {
        $data = substr_replace(
          $data,
          phutil_console_format('##%s##', $text_line),
          ($cursor == $line_num)
            ? $message->getChar() - 1
            : 0,
          strlen($text_line));
      }

      $out[] = $this->renderLine($cursor, $data, $chevron, $diff);
    }

    // Print out replacement text.
    if ($message->isPatchable()) {
      $patch_lines = explode("\n", $patch);
      $patch_length = count($patch_lines);

      $patch_line = $patch_lines[0];

      $len = isset($text_lines[0]) ? strlen($text_lines[0]) : 0;

      $patched = substr_replace(
        $line_data[$line_num],
        phutil_console_format('##%s##', $patch_line),
        $start,
        $len);

      $out[] = $this->renderLine(null, $patched, false, '+');

      foreach (array_slice($patch_lines, 1) as $patch_line) {
        $out[] = $this->renderLine(
          null,
          phutil_console_format('##%s##', $patch_line), false, '+'
        );
      }
    }

    $end = min($num_lines, $cursor + $lines_of_context);
    for (; $cursor < $end; $cursor++) {
      $out[] = $this->renderLine($cursor, $line_data[$cursor]);
    }
    $out[] = null;

    return implode("\n", $out);
  }

  protected function renderLine($line, $data, $chevron = false, $diff = null) {
    $chevron = $chevron ? '>>>' : '';
    return sprintf(
      "    %3s %1s %6s %s",
      $chevron,
      $diff,
      $line,
      $data);
  }

  public function renderOkayResult() {
    return
      phutil_console_format("<bg:green>** OKAY **</bg> No lint warnings.\n");
  }
}
