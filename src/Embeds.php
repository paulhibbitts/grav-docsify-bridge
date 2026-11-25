<?php
namespace OPG;

// Embed shortcodes (Grav) ↔ HTML that Docsify shows as it is. Each embed keeps its shortcode in a hidden note on the
// line above it, so the way back gives the exact shortcode again.
//   [googleslides title="…"]address[/googleslides], [iframe url="…"/], [youtube]address[/youtube] → a responsive
//     iframe, as in the Docsify course template (<div class="video-container-16by9"><iframe …></iframe></div>)
//   [embedly]address[/embedly], [linkpreviewcard url="…"] → an Embedly card (<a class="embedly-card" …>), which
//     Docsify-This and the kits load
//   [pdf title="…"]address[/pdf] → a link
//   ![alt](image.jpg?cropZoom=800,300) – an image Grav crops to a wide banner → the kits' banner image
//     (![alt](image.jpg ':class=banner-image')), as Docsify ignores Grav's image options
//   [safe-email …]address[/safe-email] → an email link
//   [button label="…" url="…" /] → a link button, as in the Starter Kits ([label](url ':class=button'))
//   [h5p url="…" /] or [h5p]…[/h5p] → an H5P iframe and its resizer script, as in the Starter Kits
//   [fa="calendar-o"][/fa] → a Font Awesome icon in Docsify-This's own form (:far fa-calendar fa-fw:), kept on its
//     line, since icons sit inside headings and links
//   [badge]Due Friday[/badge] or [badge label="Due Friday"][/badge] → the Starter Kits' badge (<span class='badge'>)
// Guides and courses use the same theme (Quark 2 Open Publishing), so they share all of these.
class Embeds
{
    private const NOTE = 'grav-embed';

    // ---- Grav → Docsify ----

    public static function toDocsify(string $markdown): string
    {
        // Grav's summary break (=== on a line of its own after a blank line, as in a week's page) isn't shown: kept
        // in a hidden note. (Right after a line of text, === underlines a heading instead, so that's left alone.)
        $markdown = preg_replace_callback('/(?<=\n\n)===[ \t]*(?=\n|$)/', function ($match) {
            return self::hiddenLine($match[0]);
        }, $markdown);
        // the Pressbooks Converter's line about what the Grav site supports ("*[Grav Open Publishing Space](…)
        // supports embedded video, audio, and H5P activities. …*") isn't about Docsify: kept in a hidden note
        $markdown = preg_replace_callback('/^\*\[Grav [^\]]+\]\([^)]*\) supports embedded video, audio, and H5P activities\.[^\n]*\*[ \t]*$/m', function ($match) {
            return self::hiddenLine($match[0]);
        }, $markdown);
        // the badge (the label form is marked, so it goes back the same way)
        $markdown = preg_replace('/\[badge\](.*?)\[\/badge\]/', "<span class='badge'>$1</span>", $markdown);
        $markdown = preg_replace('/\[badge label="([^"]*)"\]\[\/badge\]/', "<span class='badge' data-grav='label'>$1</span>", $markdown);
        $markdown = preg_replace_callback('/\[fa=?"?([a-z0-9-]+)"?[^\]]*\](?:\[\/fa\])?/', [self::class, 'icon'], $markdown);
        $patterns = [
            '/\[googleslides((?: [^\]]*)?)\](.*?)\[\/googleslides\]/' => 'slides',
            '/\[youtube((?: [^\]]*)?)\](.*?)\[\/youtube\]/' => 'youtube',
            '/\[iframe((?: [^\]]*)?)\s*\/\]/' => 'iframe',
            '/\[embedly((?: [^\]]*)?)\](.*?)\[\/embedly\]/' => 'embedly',
            '/\[pdf((?: [^\]]*)?)\](.*?)\[\/pdf\]/' => 'pdf',
            '/\[linkpreviewcard((?: [^\]]*)?)\s*\/?\]/' => 'linkpreview',
            '/\[safe-email((?: [^\]]*)?)\](.*?)\[\/safe-email\]/' => 'email',
            '/\[button((?: [^\]]*)?)\s*\/\]/' => 'button',
            '/\[h5p((?: [^\]]*)?)\s*\/\]/' => 'h5p',
            '/\[h5p((?: [^\]]*)?)\](.*?)\[\/h5p\]/' => 'h5p',
            // [h5p url="…"] with no closing tag, as the Pressbooks Converter writes it. Not one that ends in /],
            // has a [/h5p] later on its line, or is in the hidden note of one already converted above
            '/(?<!' . self::NOTE . ': )\[h5p((?: [^\]]*)?)(?<!\/)\](?![^\n]*\[\/h5p\])/' => 'h5p',
            '/!\[([^\]]*)\]\(([^)\s?]+\?cropZoom=[0-9]+,[0-9]+)\)/' => 'banner',
        ];
        foreach ($patterns as $pattern => $kind) {
            $text = $markdown;
            // PREG_OFFSET_CAPTURE (below): each match is [text, position]
            $markdown = preg_replace_callback($pattern, function ($match) use ($kind, $text) {
                $address = '';
                if (isset($match[2])) {
                    $address = trim($match[2][0]);
                }
                if ($kind === 'banner') {
                    $html = self::banner($match[1][0], $address);
                } else {
                    $html = self::html($kind, self::attributes($match[1][0]), $address);
                }
                if ($html === '') {
                    return $match[0][0];
                }
                // inside a > quote (a callout), the note and the HTML keep the > so they stay in the box
                $quote = '';
                if (self::isInQuote($text, $match[0][1])) {
                    $quote = '> ';
                }
                // the note and the HTML each on a line of their own, as Docsify shows HTML that starts a line
                return "\n" . $quote . '<!-- ' . self::NOTE . ': ' . $match[0][0] . " -->\n" . $quote . $html . "\n" . $quote;
            }, $markdown, -1, $count, PREG_OFFSET_CAPTURE);
        }
        return $markdown;
    }

    // A part of another page shown here ([plugin:page-inject](…)) only works in Grav: kept in a hidden note, set aside
    // with the code (see Helpers::hideCode) so its link isn't converted. $withLink (when the original site's address
    // is known): a link to that page on the Grav site goes below the note, as an embed's HTML does, and goes again on
    // the way back. Example: [plugin:page-inject](../web-pick-of-the-week/) →
    //   <!-- grav-embed: [plugin:page-inject](../web-pick-of-the-week/) -->
    //   [See it on the original site](../web-pick-of-the-week/)   (made a full link later, see Links::gravOnly)
    public static function hideInjectLines(string $markdown, array &$codeParts, bool $withLink = false): string
    {
        return preg_replace_callback('/^\[plugin:page-inject\]\(([^)]*)\)[ \t]*$/m', function ($match) use (&$codeParts, $withLink) {
            if (!$withLink) {
                $codeParts[] = self::hiddenLine($match[0]);
                return Helpers::CODE_MARK . (count($codeParts) - 1) . Helpers::CODE_MARK;
            }
            $codeParts[] = '<!-- ' . self::NOTE . ': ' . str_replace('-->', '--&gt;', $match[0]) . ' -->';
            $note = Helpers::CODE_MARK . (count($codeParts) - 1) . Helpers::CODE_MARK;
            return "\n" . $note . "\n[See it on the original site](" . trim($match[1]) . ")\n";
        }, $markdown);
    }

    // Whether $position is on a line starting with > (a quote or callout)
    private static function isInQuote(string $text, int $position): bool
    {
        $before = substr($text, 0, $position);
        $lineStart = strrpos($before, "\n");
        if ($lineStart === false) {
            $lineStart = -1;
        }
        return substr($text, $lineStart + 1, 1) === '>';
    }

    // An embed shortcode → the HTML or link Docsify shows; '' when it has no address to use.
    // $title (a shortcode's title="…") falls back to a plain name, such as 'Slides'.
    private static function html(string $kind, array $attributes, string $address): string
    {
        $title = $attributes['title'] ?? '';
        $url = $attributes['url'] ?? '';
        switch ($kind) {
            case 'slides':
                return self::iframe($address, self::titleOr($title, 'Slides'));
            case 'youtube':
                $id = self::youtubeId($address);
                if ($id === '') {
                    return '';
                }
                return self::iframe('https://www.youtube-nocookie.com/embed/' . $id, self::titleOr($title, 'Video'));
            case 'iframe':
                $url = $attributes['url'] ?? $attributes['src'] ?? '';
                if ($url === '') {
                    return '';
                }
                return self::iframe($url, self::titleOr($title, 'Embedded content'));
            case 'embedly':
                return self::embedlyCard($address);
            case 'pdf':
                return '[' . self::titleOr($title, $address) . '](' . $address . ')';
            case 'linkpreview':
                if ($url === '') {
                    return '';
                }
                return self::embedlyCard($url);
            case 'email':
                return '[' . $address . '](mailto:' . $address . ')';
            case 'h5p':
                // url="…" or the address between the tags works outside Grav ([h5p id="…"] needs the site's settings)
                if ($url === '' && str_starts_with($address, 'http')) {
                    $url = $address;
                }
                if ($url === '') {
                    return '';
                }
                return self::h5p($url, self::titleOr($title, 'Interactive content'));
            case 'button':
                if ($url === '') {
                    return '';
                }
                $label = $attributes['label'] ?? $url;
                return '[' . $label . '](' . $url . ' \':class=button\')';
        }
        return '';
    }

    private static function titleOr(string $title, string $default): string
    {
        if ($title !== '') {
            return $title;
        }
        return $default;
    }

    // An H5P activity, as in the Starter Kits: its iframe and H5P's resizer script
    private static function h5p(string $url, string $title): string
    {
        return '<iframe src="' . htmlspecialchars($url) . '" title="' . htmlspecialchars($title) . '" width="100%" height="400" frameborder="0" allowfullscreen="allowfullscreen"></iframe>'
            . '<script src="https://h5p.org/sites/all/modules/h5p/library/js/h5p-resizer.js" charset="UTF-8"></script>';
    }

    private static function embedlyCard(string $url): string
    {
        return '<a class="embedly-card" data-card-theme="light" data-card-controls="0" data-card-align="left" href="'
            . htmlspecialchars($url) . '">' . htmlspecialchars($url) . '</a>';
    }

    // An image cropped to a wide banner (at least twice as wide as it's tall) → the kits' banner image; other crops
    // stay as they are ('' = unchanged)
    private static function banner(string $alt, string $address): string
    {
        preg_match('/^(.*)\?cropZoom=([0-9]+),([0-9]+)$/', $address, $found);
        if ((int) $found[2] < 2 * (int) $found[3]) {
            return '';
        }
        // a crop this converter made from a kit's tall banner (1200,420) goes back to it
        $class = 'banner-image';
        if ($found[2] . ',' . $found[3] === Links::BANNER_CROPS['banner-tall-image']) {
            $class = 'banner-tall-image';
        }
        return '![' . $alt . '](' . $found[1] . " ':class=" . $class . "')";
    }

    // Grav's Font Awesome 4 names: a name ending in -o is the regular (outline) style in Font Awesome 6, others solid
    private static function icon(array $match): string
    {
        $name = $match[1];
        $style = 'fas';
        if (str_ends_with($name, '-o')) {
            $style = 'far';
            $name = substr($name, 0, -2);
        }
        return '<!-- ' . self::NOTE . ': ' . $match[0] . ' -->:' . $style . ' fa-' . $name . ' fa-fw:';
    }

    // A responsive iframe, as in the Docsify course template (Docsify-This and the kits style video-container-16by9)
    private static function iframe(string $url, string $title): string
    {
        return '<div class="video-container-16by9"><iframe src="' . htmlspecialchars($url) . '" title="' . htmlspecialchars($title)
            . '" frameborder="0" allowfullscreen="true"></iframe></div>';
    }

    // https://www.youtube.com/watch?v=ID, https://youtu.be/ID or just the ID → ID
    private static function youtubeId(string $address): string
    {
        if (preg_match('/(?:v=|youtu\.be\/|embed\/)([A-Za-z0-9_-]{6,})/', $address, $found) === 1) {
            return $found[1];
        }
        if (preg_match('/^[A-Za-z0-9_-]{6,}$/', $address) === 1) {
            return $address;
        }
        return '';
    }

    // ' title="Slides" url="…"' → ['title' => 'Slides', 'url' => '…']
    private static function attributes(string $text): array
    {
        preg_match_all('/([a-z][a-z0-9_-]*)="([^"]*)"/i', $text, $found, PREG_SET_ORDER);
        $attributes = [];
        foreach ($found as $match) {
            $attributes[strtolower($match[1])] = $match[2];
        }
        return $attributes;
    }

    // ---- Docsify → Grav ----

    // A hidden note and the line after it (the embed's HTML or link) → the original shortcode
    // A line of the author's text that Docsify shows in another way (such as a heading that repeats the H1), kept as
    // it was in a hidden note
    // $headingsMoved: the headings after it moved up a level (### → ##), so they move back down on the way back
    public static function hiddenLine(string $line, bool $headingsMoved = false): string
    {
        $note = $headingsMoved ? self::NOTE . '-line-headings-up' : self::NOTE . '-line';
        return '<!-- ' . $note . ': ' . str_replace('-->', '--&gt;', $line) . ' -->';
    }

    public static function toGrav(string $markdown): string
    {
        // Docsify's {docsify-ignore} comments keep a heading out of the sidebar; Grav has no use for them
        $markdown = preg_replace('/[ \t]*<!--\s*\{docsify-ignore(?:-all)?\}\s*-->/', '', $markdown);
        // a hidden line whose following headings moved up: they move back down (## → ###), outside code
        if (preg_match('/<!-- ' . self::NOTE . '-line-headings-up: /', $markdown) === 1) {
            $codeParts = [];
            $markdown = Helpers::hideCode($markdown, $codeParts);
            $markdown = preg_replace_callback('/(<!-- ' . self::NOTE . '-line-headings-up: .*? -->)(.*)$/s', function ($match) {
                return $match[1] . preg_replace('/^(#{2,5}\s)/m', '#$1', $match[2]);
            }, $markdown);
            $markdown = Helpers::showCode($markdown, $codeParts);
            $markdown = str_replace('<!-- ' . self::NOTE . '-line-headings-up: ', '<!-- ' . self::NOTE . '-line: ', $markdown);
        }
        $markdown = preg_replace_callback('/<!-- ' . self::NOTE . '-line: (.*?) -->/', function ($match) {
            return str_replace('--&gt;', '-->', $match[1]);
        }, $markdown);
        // icons first: their note and icon share a line
        $markdown = preg_replace('/<!-- ' . self::NOTE . ': (\[fa[^\n]*?) -->:fa[srb] fa-[a-z0-9-]+ fa-fw:/', '$1', $markdown);
        $markdown = preg_replace('/\n?(> )?<!-- ' . self::NOTE . ': (.*?) -->\n(?:> )?[^\n]*\n?(?(1)> )/', '$2', $markdown);
        // badges: the label form, then any other (spaces inside are left out)
        $markdown = preg_replace("/<span class='badge' data-grav='label'>(.*?)<\/span>/", '[badge label="$1"][/badge]', $markdown);
        $markdown = preg_replace('/<span class=([\'"])badge\1>\s*(.*?)\s*<\/span>/', '[badge]$2[/badge]', $markdown);
        // a link button made in Docsify: [Quiz due Jun 4th](https://example.org/quiz ':class=button')
        // → [button label="Quiz due Jun 4th" url="https://example.org/quiz" /] ((?<!!): a link, not an image)
        $markdown = preg_replace_callback('/(?<!!)\[([^\]]+)\]\(([^)\s]+) [\'"]:class=button[\'"]\)/', function ($match) {
            return '[button label="' . str_replace('"', '&quot;', $match[1]) . '" url="' . $match[2] . '" /]';
        }, $markdown);
        return $markdown;
    }

    // An Embedly card made by hand in Docsify: <a class="embedly-card" … href="https://example.org/article">…</a>
    // → [embedly]https://example.org/article[/embedly], Grav's own shortcode (the card shows the page's title itself).
    // Only for pages made in Docsify – a page this bridge wrote keeps its Grav text exactly, cards and all.
    public static function embedlyCardsToGrav(string $markdown): string
    {
        return preg_replace_callback('/<a\s[^>]*class="embedly-card"[^>]*>.*?<\/a>/is', function ($match) {
            if (preg_match('/\shref="([^"]+)"/i', $match[0], $found) !== 1) {
                return $match[0];
            }
            return '[embedly]' . html_entity_decode($found[1], ENT_QUOTES) . '[/embedly]';
        }, $markdown);
    }

    // An email link written in Docsify, showing its own address: [name@example.org](mailto:name@example.org)
    // → [safe-email autolink="true"]name@example.org[/safe-email], as Open Course Space writes them (hidden from spam
    // bots). Links with other text, such as [Email me](mailto:…), stay as they are. A plain address on a line of its
    // own, which Docsify links but Grav doesn't, becomes the same shortcode.
    public static function emailLinksToGrav(string $markdown): string
    {
        $markdown = preg_replace_callback('/\[([^\]]+)\]\(mailto:([^)\s]+)\)/i', function ($match) {
            if (strcasecmp(trim($match[1]), $match[2]) !== 0) {
                return $match[0];
            }
            return '[safe-email autolink="true"]' . $match[2] . '[/safe-email]';
        }, $markdown);
        return preg_replace('/^([ \t]*)([\w.+-]+@[\w-]+(?:\.[\w-]+)+)([ \t]*)$/m', '$1[safe-email autolink="true"]$2[/safe-email]$3', $markdown);
    }

    // Embedded pages written in Docsify as HTML (no hidden note) → Grav's embed shortcodes, as Grav 2 shows a raw
    // <iframe> as text: Google Slides → [googleslides], YouTube → [youtube], H5P → [h5p] (which resizes it, so H5P's
    // resizer script is left out), anything else → [iframe url="…" /] (16:9 inside the Starter Kits'
    // video-container-16by9, otherwise the shortcode's own size)
    public static function iframesToGrav(string $markdown): string
    {
        // an iframe, maybe in a <div>, maybe followed by H5P's resizer script
        $pattern = '/(?:<div class="([a-z0-9 -]*)">\s*)?<iframe\s([^>]*)>\s*<\/iframe>(?:\s*<\/div>)?(\s*<script [^>]*h5p-resizer[^>]*><\/script>)?/i';
        return preg_replace_callback($pattern, function ($match) {
            // the iframe's src and title
            if (preg_match('/\ssrc="([^"]+)"/i', ' ' . $match[2], $found) !== 1) {
                return $match[0];
            }
            $url = html_entity_decode($found[1], ENT_QUOTES);
            $title = '';
            if (preg_match('/\stitle="([^"]*)"/i', ' ' . $match[2], $found) === 1) {
                $title = ' title="' . str_replace('"', "'", html_entity_decode($found[1], ENT_QUOTES)) . '"';
            }
            if (str_contains($url, 'docs.google.com/presentation')) {
                return '[googleslides' . $title . ']' . $url . '[/googleslides]';
            }
            if (str_contains($url, '/h5p/embed/') || str_contains($url, 'h5p.com/content/')) {
                return '[h5p url="' . $url . '"' . $title . ' /]';
            }
            // a PDF shown with Google's document viewer: docs.google.com/viewer?url=https://…/file.pdf
            if (preg_match('/docs\.google\.com\/viewer\?(?:.*&)?url=([^&]+\.pdf)/i', $url, $found) === 1) {
                return '[pdf]' . urldecode($found[1]) . '[/pdf]';
            }
            if (preg_match('/youtube(?:-nocookie)?\.com\/embed\/([A-Za-z0-9_-]+)/', $url, $found) === 1) {
                return '[youtube]https://www.youtube.com/watch?v=' . $found[1] . '[/youtube]';
            }
            $ratio = str_contains($match[1], '16by9') ? ' ratio="16:9"' : '';
            return '[iframe url="' . $url . '"' . $ratio . $title . ' /]';
        }, $markdown);
    }

    // Icons written in Docsify (no hidden note): :fas fa-calendar fa-pull-left: → [fa="calendar"][/fa], and the
    // regular style (far) → Font Awesome 4's -o name, as Grav uses: :far fa-calendar: → [fa="calendar-o"][/fa].
    // Options such as fa-fw are left out. Font Awesome 4, 6 or 7 icons shown as images from api.iconify.design (as in
    // the Starter Kits' sidebar links) become icons too: Grav can't show those images (it reads ?height=16 as its own
    // image option).
    public static function iconsToGrav(string $markdown): string
    {
        // Font Awesome 4.7's own icons (as older converted sites have them): the same name
        $markdown = preg_replace('/!\[[^\]]*\]\(https:\/\/api\.iconify\.design\/fa\/([a-z0-9-]+)\.svg[^)\s]*\)/', '[fa="$1"][/fa] ', $markdown);
        $markdown = preg_replace_callback('/!\[[^\]]*\]\(https:\/\/api\.iconify\.design\/fa[67]-(solid|regular|brands)\/([a-z0-9-]+)\.svg[^)\s]*\)/', function ($match) {
            $name = $match[2];
            if ($match[1] === 'regular' && !str_ends_with($name, '-o')) {
                $name .= '-o';
            }
            return '[fa="' . $name . '"][/fa] ';
        }, $markdown);
        return preg_replace_callback('/:(fa[srb]) fa-([a-z0-9-]+)(?: fa-[a-z0-9-]+)*:/', function ($match) {
            $name = $match[2];
            if ($match[1] === 'far' && !str_ends_with($name, '-o')) {
                $name .= '-o';
            }
            return '[fa="' . $name . '"][/fa]';
        }, $markdown);
    }
}
