<?php

if (! defined('ABSPATH')) {
    exit;
}

class HTMD_Markdown_Converter
{
    public function convert(string $html): string
    {
        if (! class_exists('DOMDocument')) {
            return trim(wp_strip_all_tags($html)) . "\n";
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $html = '<?xml encoding="utf-8" ?>' . $html;

        $loaded = $document->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return trim(wp_strip_all_tags($html));
        }

        $markdown = $this->convert_children($document);
        $markdown = html_entity_decode($markdown, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $markdown = preg_replace("/[ \t]+\n/u", "\n", $markdown);
        $markdown = preg_replace("/\n{3,}/u", "\n\n", $markdown);

        return trim($markdown) . "\n";
    }

    private function convert_children(DOMNode $node): string
    {
        $buffer = '';

        foreach ($node->childNodes as $child) {
            $buffer .= $this->convert_node($child);
        }

        return $buffer;
    }

    private function convert_node(DOMNode $node): string
    {
        if (XML_TEXT_NODE === $node->nodeType) {
            return $this->escape_text((string) preg_replace("/[ \t]+/u", ' ', $node->nodeValue));
        }

        if (XML_ELEMENT_NODE !== $node->nodeType) {
            return '';
        }

        $tag = strtolower($node->nodeName);
        $content = trim($this->convert_children($node));

        switch ($tag) {
            case 'h1':
                return '# ' . $content . "\n\n";
            case 'h2':
                return '## ' . $content . "\n\n";
            case 'h3':
                return '### ' . $content . "\n\n";
            case 'h4':
                return '#### ' . $content . "\n\n";
            case 'h5':
                return '##### ' . $content . "\n\n";
            case 'h6':
                return '###### ' . $content . "\n\n";
            case 'p':
            case 'div':
            case 'section':
            case 'article':
                return $content !== '' ? $content . "\n\n" : "\n\n";
            case 'br':
                return "  \n";
            case 'strong':
            case 'b':
                return $content !== '' ? '**' . $content . '**' : '';
            case 'em':
            case 'i':
                return $content !== '' ? '*' . $content . '*' : '';
            case 'blockquote':
                return $this->prefix_lines($content, '> ') . "\n\n";
            case 'a':
                $href = '';
                if ($node instanceof DOMElement) {
                    $href = $this->sanitize_link_destination($node->getAttribute('href'));
                }
                if ($href === '') {
                    return $content;
                }

                return '[' . ($content ?: $this->escape_text($href)) . '](' . $href . ')';
            case 'img':
                if (! ($node instanceof DOMElement)) {
                    return '';
                }

                $src = $this->sanitize_link_destination($node->getAttribute('src'));
                $alt = $this->escape_text(trim($node->getAttribute('alt')));
                if ($src === '') {
                    return $alt;
                }

                return '![' . $alt . '](' . $src . ')';
            case 'ul':
                return $this->convert_list($node, false) . "\n";
            case 'ol':
                return $this->convert_list($node, true) . "\n";
            case 'li':
                return '- ' . $content . "\n";
            case 'pre':
                return $this->code_block((string) $node->textContent);
            case 'code':
                return $this->inline_code((string) $node->textContent);
            case 'hr':
                return "---\n\n";
            case 'table':
                return $this->convert_table($node) . "\n\n";
            case 'thead':
            case 'tbody':
            case 'tfoot':
            case 'html':
            case 'body':
                return $this->convert_children($node);
            default:
                return $this->convert_children($node);
        }
    }

    private function convert_list(DOMNode $node, bool $ordered, int $level = 0): string
    {
        $lines = array();
        $index = 1;
        $indent = str_repeat('  ', max(0, $level));

        foreach ($node->childNodes as $child) {
            if (! $child instanceof DOMElement || strtolower($child->nodeName) !== 'li') {
                continue;
            }

            $inline_parts = array();
            $nested_lists = array();

            foreach ($child->childNodes as $item_child) {
                if ($item_child instanceof DOMElement && in_array(strtolower($item_child->nodeName), array('ul', 'ol'), true)) {
                    $nested_lists[] = $this->convert_list($item_child, strtolower($item_child->nodeName) === 'ol', $level + 1);
                    continue;
                }

                $inline_parts[] = $this->convert_node($item_child);
            }

            $content = trim(implode('', $inline_parts));
            if ($content === '' && empty($nested_lists)) {
                continue;
            }

            $prefix = $ordered ? $index . '. ' : '- ';
            $line = $indent . $prefix . $this->collapse_inline($content);
            if (! empty($nested_lists)) {
                $line .= "\n" . implode("\n", array_filter($nested_lists));
            }

            $lines[] = $line;
            $index++;
        }

        return implode("\n", $lines);
    }

    private function convert_table(DOMNode $table): string
    {
        $rows = array();

        foreach ($table->childNodes as $child) {
            if ($child instanceof DOMElement && in_array(strtolower($child->nodeName), array('thead', 'tbody', 'tfoot'), true)) {
                foreach ($child->childNodes as $row) {
                    if ($row instanceof DOMElement && strtolower($row->nodeName) === 'tr') {
                        $rows[] = $this->convert_table_row($row);
                    }
                }
            } elseif ($child instanceof DOMElement && strtolower($child->nodeName) === 'tr') {
                $rows[] = $this->convert_table_row($child);
            }
        }

        $rows = array_values(array_filter($rows));
        if (count($rows) < 1) {
            return '';
        }

        $header = $rows[0];
        $column_count = count($header);
        $output = '| ' . implode(' | ', $header) . " |\n";
        $output .= '| ' . implode(' | ', array_fill(0, $column_count, '---')) . " |\n";

        for ($i = 1; $i < count($rows); $i++) {
            $output .= '| ' . implode(' | ', $rows[ $i ]) . " |\n";
        }

        return trim($output);
    }

    private function convert_table_row(DOMElement $row): array
    {
        $cells = array();

        foreach ($row->childNodes as $cell) {
            if (! $cell instanceof DOMElement || ! in_array(strtolower($cell->nodeName), array('td', 'th'), true)) {
                continue;
            }

            $value = trim(preg_replace("/\s+/u", ' ', $this->convert_children($cell)));
            $cells[] = str_replace('|', '\|', $value);
        }

        return $cells;
    }

    private function collapse_inline(string $text): string
    {
        $text = preg_replace("/\s*\n+\s*/u", ' ', trim($text));

        return trim((string) $text);
    }

    private function escape_text(string $text): string
    {
        if ($text === '') {
            return '';
        }

        return str_replace(
            array('\\', '`', '*', '_', '[', ']', '<', '>', '#'),
            array('\\\\', '\`', '\*', '\_', '\[', '\]', '\<', '\>', '\#'),
            $text
        );
    }

    private function sanitize_link_destination(string $url): string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/u', $url)) {
            return '';
        }

        $parts = wp_parse_url($url);
        if (false === $parts) {
            return '';
        }

        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        if ($scheme !== '' && ! in_array($scheme, array('http', 'https', 'mailto', 'tel'), true)) {
            return '';
        }

        return str_replace(
            array('\\', ' ', ')'),
            array('%5C', '%20', '%29'),
            $url
        );
    }

    private function inline_code(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '``';
        }

        if (strpos($text, '`') === false) {
            return '`' . $text . '`';
        }

        return '`` ' . str_replace('``', '` `', $text) . ' ``';
    }

    private function code_block(string $text): string
    {
        $text = rtrim($text);
        $fence = '```';
        if (preg_match_all('/`{3,}/', $text, $matches)) {
            $longest = max(array_map('strlen', $matches[0]));
            $fence = str_repeat('`', $longest + 1);
        }

        return $fence . "\n" . $text . "\n" . $fence . "\n\n";
    }

    private function prefix_lines(string $text, string $prefix): string
    {
        $lines = preg_split("/\n/u", trim($text));
        $lines = array_map(
            static function (string $line) use ($prefix): string {
                return $prefix . $line;
            },
            array_filter($lines, static fn(string $line): bool => $line !== '')
        );

        return implode("\n", $lines);
    }
}
