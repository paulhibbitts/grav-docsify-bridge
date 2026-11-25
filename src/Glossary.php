<?php
namespace OPG;

// Glossary terms. In Grav: [term]Pressbooks[/term], or [term name="Pressbooks"]the platform[/term].
// In Docsify: a link to the term's entry on the guide's Glossary page, with the definition as its title, so it works
// with a keyboard, a screen reader and on phones: [Pressbooks](/guide/glossary.md?id=glossary-pressbooks "A book …")
class Glossary
{
    // A Glossary page's terms, from its glossary: settings. Returns lowercase term => definition.
    public static function entries(Page $page): array
    {
        $entries = [];
        foreach ((array)($page->meta['glossary'] ?? []) as $item) {
            $term = trim((string)($item['term'] ?? ''));
            if ($term !== '') {
                $entries[mb_strtolower($term)] = trim((string)($item['definition'] ?? ''));
            }
        }
        return $entries;
    }

    // Grav → Docsify. By now each [term] is in hidden notes (see Callouts::toDocsify). A term in the glossary
    // becomes a link to the Glossary page; any other term stays as it is (plain text in Docsify).
    public static function termsToLinks(string $markdown, array $glossary, string $glossaryLink): string
    {
        $pattern = '/<!-- grav: \[term( name="([^"]*)")?\] -->(.*?)<!-- grav: \[\/term\] -->/s';
        return preg_replace_callback($pattern, function ($match) use ($glossary, $glossaryLink) {
            $nameAttribute = $match[1];
            $text = $match[3];
            $name = $nameAttribute !== '' ? $match[2] : $text;
            $key = mb_strtolower(trim($name));
            if (!isset($glossary[$key])) {
                return $match[0];
            }
            $link = '[' . $text . '](' . $glossaryLink . '?id=' . self::anchor($key) . ' "' . self::plainText($glossary[$key]) . '")';
            // a term shown in other words keeps its name in a hidden note, for the way back
            if ($nameAttribute !== '') {
                $link = '<!-- grav: [term' . $nameAttribute . '] -->' . $link;
            }
            return $link;
        }, $markdown);
    }

    // Docsify → Grav: a link with a title to a Glossary page (glossary.md), or to an entry on any page that holds the
    // glossary (such as a book's Glossary section: section-15/README.md?id=glossary-…), becomes [term]…[/term] again
    // (the definition comes from the Glossary page, so the title isn't needed).
    // $termsByDefinition (definition as a title => term, see termsByDefinition) finds the term for a link shown in
    // other words, such as "shortcodes" for Shortcode, when no hidden note kept its name
    public static function linksToTerms(string $markdown, array $termsByDefinition = []): string
    {
        $pattern = '/(?:<!-- grav: \[term( name="[^"]*")\] -->)?\[([^\]]+)\]\([^)\s]*(?:glossary\.md(?:\?id=[^)\s]*)?|\.md\?id=glossary-[^)\s]*) "([^"]*)"\)/';
        return preg_replace_callback($pattern, function ($match) use ($termsByDefinition) {
            $nameAttribute = $match[1];
            $text = $match[2];
            $title = $match[3];
            if ($nameAttribute === '' && isset($termsByDefinition[$title])) {
                $term = $termsByDefinition[$title];
                // Grav matches terms whatever their case, so a name is only needed when the words differ
                if (mb_strtolower($term) !== mb_strtolower($text)) {
                    $nameAttribute = ' name="' . $term . '"';
                }
            }
            return '[term' . $nameAttribute . ']' . $text . '[/term]';
        }, $markdown);
    }

    // A Docsify Glossary page's list, as the bridge writes it: "**Term**<br>" with the definition on the next line
    // (a long glossary also has an A–Z letter bar and letter headings).
    // Returns ['entries' => [['term', 'definition'] …], 'body' => the text with the list replaced by [glossary]].
    // No entries: an empty list and the text as it was.
    public static function listToEntries(string $body): array
    {
        $lines = explode("\n", $body);
        $entries = [];
        $first = null; // where the list starts: its letter bar, first letter heading or first term
        $last = null;  // its last definition
        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];
            $isLetterBar = substr_count($line, '|') === 25; // A|B|C … Z
            $isLetterHeading = preg_match('/^<h2 id="[a-z]">[A-Z]<\/h2>$/', $line) === 1;
            $isTerm = preg_match('/^(?:<a id="[^"]*"><\/a>)?\*\*(.+)\*\*<br>$/', $line, $found) === 1;
            if ($first === null && ($isLetterBar || $isLetterHeading || $isTerm)) {
                $first = $i;
            }
            if ($isTerm && isset($lines[$i + 1])) {
                $entries[] = ['term' => $found[1], 'definition' => $lines[$i + 1]];
                $last = $i + 1;
                $i++; // skip the definition line
            }
        }
        if (count($entries) === 0) {
            return ['entries' => [], 'body' => $body];
        }
        // the text before the list, then one blank line, then [glossary] and anything after the list
        $before = rtrim(implode("\n", array_slice($lines, 0, $first)));
        $after = implode("\n", array_slice($lines, $last + 1));
        $newBody = ($before !== '' ? $before . "\n\n" : '') . '[glossary]' . $after;
        return ['entries' => $entries, 'body' => rtrim($newBody)];
    }

    // A glossary entry's anchor, as Grav names it: "Open Resources" → glossary-open-resources (a term with no
    // Latin letters or digits gets a short hash)
    public static function anchor(string $term): string
    {
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($term)), '-');
        return 'glossary-' . ($slug !== '' ? $slug : substr(md5($term), 0, 8));
    }

    // Each definition as a link's title (as termsToLinks writes it) => its term, to find a term from its link
    public static function termsByDefinition(array $entries): array
    {
        $terms = [];
        foreach ($entries as $entry) {
            $terms[self::plainText($entry['definition'])] = $entry['term'];
        }
        return $terms;
    }

    // A definition as plain text for a link's title, as Grav shows it: code keeps its text as it is, other Markdown
    // marks go, and " becomes &quot; (it would end the title)
    private static function plainText(string $definition): string
    {
        // split at backticks: the odd parts are code
        $parts = explode('`', $definition);
        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                $part = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $part); // [text](address) → text
                $part = str_replace(['**', '__'], '', $part);
            }
            $parts[$index] = $part;
        }
        return str_replace('"', '&quot;', implode('', $parts));
    }
}
