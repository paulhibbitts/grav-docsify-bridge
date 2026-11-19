<?php
namespace OPG;

use Symfony\Component\Yaml\Yaml;

class Helpers
{
    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];

    // Grav orders pages with a number in front of the folder name ('01.what-is-open-education')
    public static function isNumbered(string $folderName): bool
    {
        return preg_match('/^\d+\./', $folderName) === 1;
    }

    public static function slugFromFolder(string $folderName): string
    {
        return preg_replace('/^\d+\./', '', $folderName);
    }

    // 'Open Education Essentials' → 'open-education-essentials' (for a folder name)
    public static function slugify(string $text): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($ascii !== false && $ascii !== '') {
            $text = $ascii;
        }
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');
        if ($text === '') {
            return 'guide';
        }
        return $text;
    }

    public static function titleFromSlug(string $slug): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $slug));
    }

    public static function isImage(string $fileName): bool
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        return in_array($extension, self::IMAGE_EXTENSIONS, true);
    }

    // An image file's full path, only when it really is inside $siteFolder (a link such as ../../etc/hosts in an
    // uploaded site mustn't copy a file from elsewhere on the server); otherwise ''
    public static function imageInside(string $siteFolder, string $path): string
    {
        if (!self::isImage($path)) {
            return '';
        }
        $realSite = realpath($siteFolder);
        $realPath = realpath($path);
        if ($realSite === false || $realPath === false || !is_file($realPath)) {
            return '';
        }
        if (!str_starts_with($realPath, $realSite . DIRECTORY_SEPARATOR)) {
            return '';
        }
        return $realPath;
    }

    // Grav's last_updated ('04-10-2026 00:00', day first) as shown on the cards ('Oct 4, 2026')
    public static function shortDate(string $value): string
    {
        $dayPart = strtok(trim($value), ' ');
        $date = \DateTime::createFromFormat('d-m-Y', (string)$dayPart);
        if ($date === false) {
            return $value;
        }
        return $date->format('M j, Y');
    }

    // The hidden note that lets a Docsify page go back to Grav unchanged (Docsify doesn't show HTML comments).
    // First line: where the page came from (and its images); then its front matter exactly as written.
    public static function gravNote(Page $page, array $extra = []): string
    {
        $where = ['file' => ltrim($page->folder . '/' . $page->file, '/')];
        if (count($page->images) > 0) {
            $where['images'] = $page->images;
        }
        foreach ($extra as $name => $value) {
            $where[$name] = $value;
        }
        // a comment ends at -->, so any --> in the front matter is written as --&gt;
        $yaml = rtrim(str_replace('-->', '--&gt;', $page->yaml));
        // Docsify ends a comment early at a line that could start a new block (an empty line, or one starting with a
        // heading, list item, quote, table row or code fence, as in a setting of several lines), so each one is
        // written as "." and its text kept in the first line, to put it back exactly
        $yamlLines = explode("\n", $yaml);
        foreach ($yamlLines as $number => $line) {
            if ($yaml !== '' && preg_match('/^\s*$|^ {0,3}([#>*+=_`~<|-]|\d+[.)])/', $line) === 1) {
                $where['lines'][$number] = $line;
                $yamlLines[$number] = '.';
            }
        }
        $yaml = implode("\n", $yamlLines);
        // (with any --> written as --\u003e, which JSON reads back the same, so it can't end the comment)
        $firstLine = str_replace('-->', '--\\u003e', json_encode($where, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        // no empty line for a page without settings: Docsify would end the comment there and show the note
        if ($yaml === '') {
            return '<!-- grav: ' . $firstLine . "\n-->";
        }
        return '<!-- grav: ' . $firstLine . "\n" . $yaml . "\n-->";
    }

    // A note's Grav file is written on the server, so it must stay inside the pages folder: folders and a file name
    // of letters, numbers, . _ and -, ending in .md, e.g. '03.modules/02.module-01/course-page.md' (no '..')
    public static function isSafeGravFile(string $file): bool
    {
        if (preg_match('/^[A-Za-z0-9_-][A-Za-z0-9._-]*(\/[A-Za-z0-9_-][A-Za-z0-9._-]*)*\.md$/', $file) !== 1) {
            return false;
        }
        foreach (explode('/', $file) as $part) {
            if ($part === '..' || str_contains($part, '..')) {
                return false;
            }
        }
        return true;
    }

    // A section's or module's overview link in a sidebar: "Chapter 2 Overview" when its title starts with a number like
    // that, or else "Overview", as the Starter Kits' guides have it (Previous/Next show this text, so the number says
    // which overview it is). $named: "Additional resources Overview" instead, for a course's modules
    public static function overviewLabel(string $title, bool $named = false): string
    {
        if (preg_match('/^((?:Chapter|Module|Part|Unit|Week|Section)\s+\d+)/i', $title, $found) === 1) {
            return $found[1] . ' Overview';
        }
        return $named ? $title . ' Overview' : 'Overview';
    }

    // Reads a hidden note back. Returns null when the text doesn't start with one, otherwise
    // ['where' => what the first line holds, 'yaml' => the front matter, 'rest' => the rest of the page]
    public static function readGravNote(string $text): ?array
    {
        $found = preg_match('/^<!-- grav: (\{.*?\})\n(.*?)\n?-->\n?(.*)$/s', ltrim($text), $match);
        if ($found !== 1) {
            return null;
        }
        $where = json_decode($match[1], true);
        if (!is_array($where) || !isset($where['file']) || !self::isSafeGravFile((string)$where['file'])) {
            return null;
        }
        // the spacing around the text may only be blank space
        foreach (['before', 'after'] as $name) {
            if (isset($where[$name]) && trim((string)$where[$name]) !== '') {
                unset($where[$name]);
            }
        }
        // the front matter's lines written as "." (see gravNote())
        $yamlLines = explode("\n", $match[2]);
        foreach ($where['lines'] ?? [] as $number => $line) {
            if (is_string($line) && ($yamlLines[$number] ?? '') === '.') {
                $yamlLines[$number] = $line;
            }
        }
        unset($where['lines']);
        return [
            'where' => $where,
            'yaml' => str_replace('--&gt;', '-->', implode("\n", $yamlLines)),
            'rest' => $match[3],
        ];
    }

    // A card on the Guides or Courses page, as Quark 2 Open Publishing shows them: an image linking to the page (or ''),
    // the title as a link (an <h2>, so cards line up), then lines of text.
    // $linkImage: false for a decorative image (empty alt text, no link), so the card has one link, its title, and a
    // screen reader doesn't announce the same link twice
    // $page: the Docsify page, e.g. 'cpt363-basic/home'; $image: its address from the site's top
    // $markdownTitle: the title as a Markdown heading link to the page's file, which also works when browsing the files
    // on GitHub (course cards; guide cards keep the HTML link, so their titles stay out of the sidebar and search)
    public static function card(string $page, string $title, string $image, string $alt, array $text, string $badge = '', bool $markdownTitle = false, bool $linkImage = true): array
    {
        $card = ['<div class="card">', ''];
        if ($image !== '' && $linkImage) {
            $card[] = '[![' . $alt . '](' . $image . ' ":no-zoom")](' . $page . '.md)';
            $card[] = '';
        } elseif ($image !== '') {
            $card[] = '![](' . $image . ' ":no-zoom")';
            $card[] = '';
        }
        $card[] = self::cardTitle($page, $title, $markdownTitle, $badge);
        $card[] = '';
        foreach ($text as $line) {
            $card[] = $line;
            $card[] = '';
        }
        $card[] = '</div>';
        $card[] = '';
        return $card;
    }

    // A card's title linked to its page. On a page at the site's top, a Markdown link to the .md file, which works in
    // Docsify and on GitHub ({docsify-ignore} keeps it out of the sidebar); on a page in a folder, Docsify's own link,
    // as GitHub would read a path from the top as starting in that folder.
    // Example: ('cpt363-basic/home', 'CPT363-1', true) → ## [CPT363-1](cpt363-basic/home.md) <!-- {docsify-ignore} -->
    public static function cardTitle(string $page, string $title, bool $atTop, string $badge = ''): string
    {
        if ($atTop) {
            return '## [' . $title . '](' . $page . '.md)' . $badge . ' <!-- {docsify-ignore} -->';
        }
        return '<h2><a href="#/' . $page . '">' . $title . '</a>' . $badge . '</h2>';
    }

    // Marks text the converter adds for Docsify (titles, cards, back links …), so it can be told apart from
    // the author's own text on the way back
    public static function generated(array $lines): array
    {
        $marked = ['<!-- grav:gen -->'];
        foreach ($lines as $line) {
            $marked[] = $line;
        }
        $marked[] = '<!-- /grav:gen -->';
        return $marked;
    }

    // Removes the hidden notes for going back to Grav from every Markdown page in $folder, for cleaner pages.
    // Keeps {docsify-ignore}, which Docsify itself reads.
    public static function removeGravNotes(string $folder): void
    {
        if (!is_dir($folder)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!str_ends_with($file->getFilename(), '.md')) {
                continue;
            }
            $text = (string)file_get_contents($file->getPathname());
            // every note starts with "<!-- grav" or "<!-- /grav" (grav:, grav:gen, /grav:gen, grav-embed: …) and never
            // has --> inside, so each one ends at the first --> ((?!-->) stops a match running on into the next note)
            $note = '<!-- \/?grav(?:(?!-->).)*+-->';
            // a note on lines of its own (in a quote too: '> <!-- grav… -->') goes with its line break; one inside a line
            // (such as [term]) goes on its own
            $cleaner = preg_replace(['/^(?:>[ \t]?)*' . $note . '\n/ms', '/' . $note . '/s'], '', $text);
            // (null if a very long page is too much for PHP's pattern matching: the page is then left as it is)
            // only a page that had notes is rewritten (an empty _navbar.md's line break is kept: Docsify skips an
            // empty file and keeps the last page's navbar), and a page that was only a note keeps a line break too
            if ($cleaner !== null && $cleaner !== $text) {
                $cleaner = ltrim($cleaner, "\n");
                file_put_contents($file->getPathname(), $cleaner === '' ? "\n" : $cleaner);
            }
        }
    }

    // Quotes each line for a Markdown callout or blockquote: 'text' → '> text', and an empty line → '>'
    public static function quoteLines(string $text): string
    {
        $quoted = [];
        foreach (explode("\n", $text) as $line) {
            if ($line === '') {
                $quoted[] = '>';
            } else {
                $quoted[] = '> ' . $line;
            }
        }
        return implode("\n", $quoted);
    }

    // Code is shown as it is, so shortcodes and links inside it (such as `[example]…[/example]`) must not be
    // converted: code blocks and `code` are swapped for placeholders, and put back by showCode() afterwards
    public static function hideCode(string $markdown, array &$codeParts): string
    {
        // a fenced block (``` or ~~~ to the same fence), or `code` on one line
        $pattern = '/^(```|~~~).*?^\1|`[^`\n]+`/ms';
        return preg_replace_callback($pattern, function ($match) use (&$codeParts) {
            // one placeholder per line, so a code block inside a callout still gets '> ' on every line
            $placeholders = [];
            foreach (explode("\n", $match[0]) as $line) {
                if ($line === '') {
                    $placeholders[] = '';
                    continue;
                }
                $codeParts[] = $line;
                $placeholders[] = self::CODE_MARK . (count($codeParts) - 1) . self::CODE_MARK;
            }
            return implode("\n", $placeholders);
        }, $markdown);
    }

    public static function showCode(string $markdown, array $codeParts): string
    {
        return preg_replace_callback('/' . self::CODE_MARK . '(\d+)' . self::CODE_MARK . '/u', function ($match) use ($codeParts) {
            return $codeParts[(int)$match[1]];
        }, $markdown);
    }

    // Grav shows footnotes only with Markdown Extra on (it's off by default), so each page of a site made in Docsify
    // that has a footnote turns it on for itself. Example: "Markdown[^1]" … "[^1]: [Markdown](https://…)"
    public static function turnOnFootnotes(Site $site): void
    {
        foreach (self::allPages($site) as $page) {
            $codeParts = [];
            $text = self::hideCode($page->body, $codeParts);
            // a footnote's text: [^1]: at the start of a line
            if (preg_match('/^\[\^[^\]\s]+\]:/m', $text) === 1 && !isset($page->meta['markdown'])) {
                $page->meta['markdown'] = ['extra' => true];
                $page->yaml = rtrim(Yaml::dump($page->meta, 4, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
            }
        }
    }

    // Pages whose own text talks about Docsify, which may read oddly on a Grav site, so the notes point to them.
    // Example: "This version is published with Docsify-This" or "its card on the Guides page (home.md)".
    // Mentions of Docsify-This as a tool ("see Docsify-This") aren't counted.
    public static function docsifyWordingNotes(Site $site): array
    {
        $notes = [];
        foreach (self::allPages($site) as $page) {
            // the page's words only: no code blocks, link addresses or web addresses
            $text = preg_replace('/^(```|~~~).*?^\1/ms', '', $page->body);
            $text = preg_replace('/\]\([^)]*\)/', ']', $text);
            $text = preg_replace('/https?:\/\/\S+/', '', $text);
            // Docsify's own files, or the site being "published with/by Docsify"
            $pattern = '/(?<![\w\/.-])(_sidebar\.md|_navbar\.md|_coverpage\.md|_footer\.md|home\.md|index\.html)\b|published (?:with|by) Docsify/i';
            if (preg_match($pattern, $text, $found) === 1) {
                $notes[] = '"' . $page->title() . '" mentions Docsify ("' . $found[0] . '") – check its wording for Grav';
            }
        }
        return $notes;
    }

    // Every page of a site: the Guides page, each guide with its sections, pages and menu pages, and a course's pages
    private static function allPages(Site $site): array
    {
        $pages = [];
        if ($site->guidesPage !== null) {
            $pages[] = $site->guidesPage;
        }
        foreach ($site->guides as $guide) {
            $pages[] = $guide;
            foreach ($guide->children as $section) {
                $pages[] = $section;
                $pages = array_merge($pages, $section->children);
            }
            $pages = array_merge($pages, $guide->menuPages);
        }
        foreach ([$site->courseHome, $site->modulesPage] as $parent) {
            if ($parent !== null) {
                $pages[] = $parent;
                $pages = array_merge($pages, $parent->children);
            }
        }
        $pages = array_merge($pages, $site->coursePages);
        return $pages;
    }

    // a character that never appears in written text (Unicode private use), to mark the placeholders
    public const CODE_MARK = "\u{E000}";

    // Turns runs of blank lines into one blank line – except next to an embed's note line, where the extra blank line
    // lets the way back restore the blank line beside the shortcode.
    public static function collapseBlankLines(string $text): string
    {
        return preg_replace_callback("/\n{3,}/", function ($match) use ($text) {
            $linesBefore = explode("\n", substr($text, 0, $match[0][1]));
            $lineAfter = explode("\n", substr($text, $match[0][1] + strlen($match[0][0])))[0];
            // the note is the line above the embed's HTML, so it's two lines up when the blank lines come after
            $noteBefore = self::isEmbedNote($linesBefore[count($linesBefore) - 2] ?? '');
            $noteAfter = self::isEmbedNote($lineAfter);
            return ($noteBefore || $noteAfter) ? "\n\n\n" : "\n\n";
        }, $text, -1, $count, PREG_OFFSET_CAPTURE);
    }

    private static function isEmbedNote(string $line): bool
    {
        return str_starts_with(ltrim($line, '> '), '<!-- grav-embed:');
    }
}
