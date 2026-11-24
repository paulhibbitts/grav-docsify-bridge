<?php
namespace OPG;

// Glossary terms. In Grav: [term]Pressbooks[/term], or [term name="Pressbooks"]the platform[/term].
// In Docsify: a link to the guide's Glossary page, with the definition as its title, so it works with a keyboard,
// a screen reader and on phones: [Pressbooks](/guide/glossary.md "A book publishing platform …")
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
            $link = '[' . $text . '](' . $glossaryLink . ' "' . self::plainText($glossary[$key]) . '")';
            // a term shown in other words keeps its name in a hidden note, for the way back
            if ($nameAttribute !== '') {
                $link = '<!-- grav: [term' . $nameAttribute . '] -->' . $link;
            }
            return $link;
        }, $markdown);
    }

    // Docsify → Grav: a link to a Glossary page with a title becomes [term]…[/term] again (the definition comes
    // from the Glossary page, so the title isn't needed)
    public static function linksToTerms(string $markdown): string
    {
        $pattern = '/(?:<!-- grav: \[term( name="[^"]*")\] -->)?\[([^\]]+)\]\([^)\s]*glossary\.md "[^"]*"\)/';
        return preg_replace_callback($pattern, function ($match) {
            return '[term' . $match[1] . ']' . $match[2] . '[/term]';
        }, $markdown);
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
