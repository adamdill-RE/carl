<?php

declare(strict_types=1);

namespace Carl\Analysis;

/**
 * An answer, turned into blocks a template can render.
 *
 * The alternative was a Markdown renderer, and it is the wrong tool for this
 * job twice over. The CSP is `style-src 'self'` and `script-src 'self'` with
 * no inline anything (hosting Section 8.5), so any HTML this produced would
 * have to be sanitised anyway; and the input comes from outside the
 * application, which makes "a small Markdown parser" a piece of untrusted
 * input handling nobody asked for.
 *
 * So this does not produce HTML at all. It produces a list of typed blocks --
 * heading, paragraph, list -- and the template escapes every string in them
 * with the same `$e()` every other page uses. There is no path by which
 * anything in the answer becomes markup.
 *
 * `Prompt` asks for plain text. This still strips the light Markdown a model
 * writes out of habit -- leading `#`, `**bold**`, `- ` bullets -- because
 * asking is not the same as receiving, and a stray `**` on a page is a
 * cosmetic bug that will otherwise be reported as a real one.
 *
 * @phpstan-type Block array{type:'heading'|'paragraph'|'list',text?:string,items?:list<string>}
 */
final class Prose
{
    /**
     * @return list<array{type:string,text?:string,items?:list<string>}>
     */
    public static function blocks(string $answer): array
    {
        $blocks = [];
        $paragraph = [];
        $items = [];

        $flushParagraph = static function () use (&$paragraph, &$blocks): void {
            if ($paragraph !== []) {
                $blocks[] = ['type' => 'paragraph', 'text' => \implode(' ', $paragraph)];
                $paragraph = [];
            }
        };
        $flushList = static function () use (&$items, &$blocks): void {
            if ($items !== []) {
                $blocks[] = ['type' => 'list', 'items' => $items];
                $items = [];
            }
        };

        foreach (\preg_split('/\R/', $answer) ?: [] as $rawLine) {
            $line = \rtrim($rawLine);
            $trimmed = \ltrim($line);

            if ($trimmed === '') {
                $flushList();
                $flushParagraph();
                continue;
            }

            // A bullet: "- ", "* " or "1. ". The number is dropped rather
            // than kept, because the list is rendered as a list and a
            // rendered "1. 1. Water the beds" is the classic double marker.
            if (\preg_match('/^(?:[-*\x{2022}]|\d+[.)])\s+(.*)$/u', $trimmed, $m) === 1) {
                $flushParagraph();
                $items[] = self::inline($m[1]);
                continue;
            }

            $flushList();

            // A heading: a "## " marker, or a short line ending in a colon,
            // which is what the prompt actually asks for.
            if (\preg_match('/^#{1,6}\s+(.*)$/', $trimmed, $m) === 1) {
                $flushParagraph();
                $blocks[] = ['type' => 'heading', 'text' => self::inline($m[1])];
                continue;
            }
            if ($paragraph === [] && \str_ends_with($trimmed, ':') && \mb_strlen($trimmed) <= 80) {
                $blocks[] = ['type' => 'heading', 'text' => self::inline(\rtrim($trimmed, ':'))];
                continue;
            }

            $paragraph[] = self::inline($trimmed);
        }

        $flushList();
        $flushParagraph();

        return $blocks;
    }

    /**
     * The first line of an answer, for a list of past analyses.
     *
     * Not `substr()`: an answer can open with a heading, and "What your
     * season says" is a better summary of it than the first 120 characters
     * of the paragraph underneath.
     */
    public static function excerpt(string $answer, int $maxChars = 160): string
    {
        foreach (self::blocks($answer) as $block) {
            $text = $block['text'] ?? ($block['items'][0] ?? '');
            if ($text === '') {
                continue;
            }
            return \mb_strlen($text) <= $maxChars
                ? $text
                : \rtrim(\mb_substr($text, 0, $maxChars - 1)) . "\u{2026}";
        }
        return '';
    }

    /** Emphasis markers a model writes even when asked not to. */
    private static function inline(string $text): string
    {
        $text = (string) \preg_replace('/\*\*(.+?)\*\*/u', '$1', $text);
        $text = (string) \preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/u', '$1', $text);
        $text = (string) \preg_replace('/`([^`]+)`/u', '$1', $text);
        return \trim($text);
    }
}
