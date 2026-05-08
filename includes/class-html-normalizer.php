<?php

if (! defined('ABSPATH')) {
    exit;
}

class HTMD_HTML_Normalizer
{
    public function normalize(string $html, array $options = array()): string
    {
        $encoding = $this->detect_encoding($html);

        if ('UTF-8' !== $encoding && function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($html, 'UTF-8', $encoding);
            if (false !== $converted) {
                $html = $converted;
            }
        }

        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);
        $html = preg_replace('#<style\b[^>]*>.*?</style>#is', '', $html);

        // PageHead bloğu (Şamile/Mektebe): tekrarlayan başlık (PartName) ve
        // <hr>'i ayıkla, içinde PageNumber varsa onu sayfa işaretçisine çevir.
        $page_number_pattern = '#<span\b[^>]*\bclass\s*=\s*[\'"][^\'"]*\bPageNumber\b[^\'"]*[\'"][^>]*>(.*?)</span>#is';

        $extract_page_marker = static function (string $raw): string {
            $text = trim((string) wp_strip_all_tags($raw));
            $text = preg_replace('/^[\s(\[\{]+|[\s)\]\}]+$/u', '', (string) $text);
            if ($text === null || $text === '') {
                return '';
            }
            return "\n\n<div>--" . $text . "--</div>\n\n";
        };

        $html = preg_replace_callback(
            '#<div\b[^>]*\bclass\s*=\s*[\'"][^\'"]*\bPageHead\b[^\'"]*[\'"][^>]*>(.*?)</div>#is',
            static function (array $matches) use ($page_number_pattern, $extract_page_marker): string {
                if (preg_match($page_number_pattern, $matches[1], $inner)) {
                    return $extract_page_marker($inner[1]);
                }
                return '';
            },
            $html
        );

        // PageHead dışında kalan bağımsız PageNumber/PartName span'leri.
        $html = preg_replace_callback(
            $page_number_pattern,
            static function (array $matches) use ($extract_page_marker): string {
                return $extract_page_marker($matches[1]);
            },
            $html
        );

        $html = preg_replace('#<span\b[^>]*\bclass\s*=\s*[\'"][^\'"]*\bPartName\b[^\'"]*[\'"][^>]*>.*?</span>#is', '', $html);

        if (empty($options['keep_footnotes'])) {
            $html = preg_replace('#<div\b[^>]*\bclass\s*=\s*[\'"][^\'"]*\bfootnote\b[^\'"]*[\'"][^>]*>.*?</div>#is', '', $html);
            $html = preg_replace('#<span\b[^>]*\bclass\s*=\s*[\'"][^\'"]*\bfootnote\b[^\'"]*[\'"][^>]*>.*?</span>#is', '', $html);
            // Metin içindeki dipnot işaretçileri: <sup>(1)</sup> veya
            // <sup><font color="#be0000">(1)</font></sup> gibi yalnızca
            // sayı içeren üst simgeleri kaldır. Düz metindeki "(859)" gibi
            // parantezli sayılar <sup> ile sarılmadığı için korunur.
            $html = preg_replace(
                '#<sup\b[^>]*>\s*(?:<font\b[^>]*>)?\s*\(?\s*\d+\s*\)?\s*(?:</font>)?\s*</sup>#iu',
                '',
                $html
            );
        }

        $html = preg_replace('#<(font|span)\b[^>]*>#i', '', $html);
        $html = preg_replace('#</(font|span)>#i', '', $html);
        $html = preg_replace('#<hr\b[^>]*>#i', "\n\n<hr />\n\n", $html);
        $html = preg_replace("/\r\n|\r/u", "\n", $html);

        // Bazı kaynaklar (ör. Şamile/Mektebe çıktıları) eşleşmeyen </p>
        // kapanış etiketlerini paragraf ayırıcı olarak kullanır; bu etiketler
        // DOMDocument tarafından sessizce düşürülür ve komşu paragrafların
        // son/ilk kelimeleri (Arapça metinde aralarında boşluk olmadığı için)
        // birbirine yapışır. Parse öncesi tüm <p>/</p> etiketlerini gerçek
        // satır kırmasına çevirip sorunu kökten çözüyoruz.
        $html = preg_replace('#</?p\b[^>]*>#i', "\n\n", $html);

        return trim($html);
    }

    private function detect_encoding(string $html): string
    {
        if (preg_match('/charset=([a-zA-Z0-9\-_]+)/i', $html, $matches)) {
            return strtoupper($matches[1]);
        }

        if (function_exists('mb_detect_encoding')) {
            $encoding = mb_detect_encoding($html, array('UTF-8', 'Windows-1256', 'ISO-8859-1', 'ASCII'), true);
            if ($encoding) {
                return $encoding;
            }
        }

        return 'UTF-8';
    }
}
