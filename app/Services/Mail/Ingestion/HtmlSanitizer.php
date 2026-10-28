<?php

namespace App\Services\Mail\Ingestion;

use HTMLPurifier;
use HTMLPurifier_Config;
use Illuminate\Support\Str;

/**
 * Email HTML is hostile input. We purify once on ingest and store the result, then render it
 * inside a sandboxed iframe on the client as a second layer.
 */
class HtmlSanitizer
{
    protected ?HTMLPurifier $purifier = null;

    public function sanitize(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $html = $this->stripDangerousBlocks($html);

        $clean = $this->purifier()->purify($html);

        $clean = preg_replace_callback('/<a\b([^>]*)>/i', function (array $m) {
            $attrs = preg_replace('/\s(target|rel)=("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $m[1]) ?? $m[1];

            return '<a'.$attrs.' target="_blank" rel="noopener noreferrer nofollow">';
        }, $clean) ?? $clean;

        return trim($clean) === '' ? null : $clean;
    }

    /**
     * Produce a plain text version of an HTML body when the message had none.
     */
    public function toText(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $text = preg_replace('/<(script|style|head)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
        $text = preg_replace('/<br\s*\/?>|<\/(p|div|li|tr|h[1-6]|blockquote)>/i', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text) === '' ? null : trim($text);
    }

    protected function stripDangerousBlocks(string $html): string
    {
        $html = preg_replace('/<!DOCTYPE[^>]*>/i', '', $html) ?? $html;
        $html = preg_replace('/<(script|iframe|object|embed|applet|form|base|meta|link|title)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
        $html = preg_replace('/<(script|iframe|object|embed|applet|form|base|meta|link|input|button|textarea|select)\b[^>]*\/?>/i', '', $html) ?? $html;

        return $html;
    }

    protected function purifier(): HTMLPurifier
    {
        if ($this->purifier) {
            return $this->purifier;
        }

        $cachePath = storage_path('framework/cache/purifier');

        if (! is_dir($cachePath)) {
            @mkdir($cachePath, 0755, true);
        }

        $config = HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('Cache.SerializerPath', $cachePath);
        $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
        $config->set('HTML.DefinitionID', 'tandem-email-html');
        $config->set('HTML.DefinitionRev', 1);
        $config->set('HTML.TidyLevel', 'none');
        $config->set('HTML.SafeIframe', false);
        $config->set('HTML.ForbiddenElements', ['script', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'link', 'meta', 'base', 'applet', 'frame', 'frameset', 'noscript']);
        $config->set('HTML.ForbiddenAttributes', ['*@onclick', '*@onload', '*@onerror', '*@onmouseover', '*@onfocus']);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('Attr.EnableID', false);
        $config->set('Attr.AllowedRel', ['noopener', 'noreferrer', 'nofollow']);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true, 'cid' => true, 'data' => true]);
        $config->set('URI.DisableExternalResources', false);
        $config->set('URI.DisableResources', false);
        $config->set('CSS.AllowTricky', true);
        $config->set('CSS.AllowImportant', true);
        $config->set('CSS.Proprietary', true);
        $config->set('CSS.MaxImgLength', null);
        $config->set('HTML.MaxImgLength', null);
        $config->set('Core.RemoveInvalidImg', false);
        $config->set('AutoFormat.RemoveEmpty', false);

        $definition = $config->maybeGetRawHTMLDefinition();

        if ($definition) {
            $definition->addAttribute('table', 'bgcolor', 'Text');
            $definition->addAttribute('td', 'bgcolor', 'Text');
            $definition->addAttribute('tr', 'bgcolor', 'Text');
            $definition->addAttribute('td', 'background', 'URI');
            $definition->addAttribute('table', 'background', 'URI');
            $definition->addAttribute('img', 'data-src', 'URI');
            $definition->addElement('center', 'Block', 'Flow', 'Common');
            $definition->addElement('font', 'Inline', 'Inline', 'Common', ['color' => 'Color', 'face' => 'Text', 'size' => 'Text']);
        }

        return $this->purifier = new HTMLPurifier($config);
    }

    public function snippet(?string $text, ?string $html, int $length = 180): ?string
    {
        $source = $text ?? $this->toText($html);

        if ($source === null) {
            return null;
        }

        $lines = collect(preg_split('/\r?\n/', $source) ?: [])
            ->map(fn (string $line) => trim($line))
            ->reject(fn (string $line) => $line === '' || Str::startsWith($line, '>'))
            ->takeUntil(fn (string $line) => preg_match('/^(On .+ wrote:|-----Original Message-----|From: .+|Sent from my )/i', $line) === 1);

        $joined = preg_replace('/\s+/', ' ', $lines->implode(' ')) ?? '';

        return Str::limit(trim($joined), $length, '…') ?: null;
    }
}
