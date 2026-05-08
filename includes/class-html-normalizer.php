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

        if (! empty($options['remove_page_headers'])) {
            $html = preg_replace('#<div class=[\'"]PageHead[\'"][^>]*>.*?</div>#is', '', $html);
        }

        if (! empty($options['strip_page_numbers'])) {
            $html = preg_replace('#<span class=[\'"]PageNumber[\'"][^>]*>.*?</span>#is', '', $html);
        } else {
            $html = preg_replace_callback(
                '#<span class=[\'"]PageNumber[\'"][^>]*>(.*?)</span>#is',
                static function (array $matches): string {
                    $text = trim((string) wp_strip_all_tags($matches[1]));
                    $text = preg_replace('/^[\s(\[\{]+|[\s)\]\}]+$/u', '', $text);
                    if ($text === null || $text === '') {
                        return '';
                    }
                    return "\n\n<div>--" . $text . "--</div>\n\n";
                },
                $html
            );
        }

        if (empty($options['keep_footnotes'])) {
            $html = preg_replace('#<div class=[\'"]footnote[\'"][^>]*>.*?</div>#is', '', $html);
            $html = preg_replace('#<span class=[\'"]footnote[\'"][^>]*>.*?</span>#is', '', $html);
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
