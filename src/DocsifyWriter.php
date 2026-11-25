<?php
namespace OPG;

// Writes guides as a Docsify guide site, laid out like the Docsify-This Multiple Guides Site and Guide Site
// templates. The same Markdown pages work in a repo shown by Docsify-This and in a Starter Kit's docs/ folder.
class DocsifyWriter
{
    // A page outside the reading order hides the sidebar with this line, at the end of the page
    public const HIDE_SIDEBAR = '<style>aside.sidebar, button.sidebar-toggle { display: none !important; } section.content { left: 0 !important; transition: none !important; }</style>';

    // Start Reading again after a guide's section cards, as Grav shows it (reading the page back into Grav removes it,
    // as Grav adds its own)
    public static function startReadingBelow(string $firstPage): string
    {
        return '**[Start Reading →](' . $firstPage . ')**';
    }

    // The Guides page's subtitle, larger and grey as in Grav (70% of the text colour passes WCAG AA contrast)
    public const SUBTITLE_STYLE = '.markdown-section > h1 + p { font-size: 1.2em; color: color-mix(in srgb, var(--color-mono-max) 70%, transparent); }';

    // Links that only work on the Grav site, and anything else worth checking (for bridge-notes.txt)
    public array $warnings = [];

    private string $pagesDir;
    private string $out;
    private bool $single;
    private string $siteUrl;
    private array $guideSlugs = [];
    // a single guide's menu pages (Topics, Resources …), linked from the site's navbar
    private array $siteMenuLinks = [];
    // kept links to a page's Markdown version, by Docsify file (see recordKeptLinks)
    private array $keptLinks = [];
    // the site title at the start of each guide's navbar, on a site with several guides
    private string $siteTitle = '';
    // the guide being written: its glossary (lowercase term => definition) and its Glossary page's Docsify file
    private array $glossary = [];
    private string $glossaryFile = '';

    // $single: a single-guide site (the guide home is home.md, sections are top-level folders, no Guides page)
    // $siteUrl: the original Grav site's address, for links to its other pages (optional)
    public function write(Site $site, string $pagesDir, string $out, bool $single = false, string $siteUrl = ''): void
    {
        $this->pagesDir = $pagesDir;
        $this->out = $out;
        $this->single = $single;
        $this->siteUrl = $siteUrl;
        $this->warnings = [];
        $this->siteMenuLinks = [];
        // the site's name when known (from a Docsify site), or else the Guides page's title
        $this->siteTitle = $site->name;
        if ($this->siteTitle === '' && $site->guidesPage !== null) {
            $this->siteTitle = $site->guidesPage->setting('title');
        }
        if ($this->siteTitle === '') {
            $this->siteTitle = 'Guides';
        }

        $guides = $site->guides;
        if ($single) {
            // a single-guide site: the first guide
            $guides = array_slice($site->guides, 0, 1);
        }
        $this->guideSlugs = [];
        foreach ($guides as $guide) {
            $this->guideSlugs[] = $guide->slug();
        }

        $cards = [];
        foreach ($guides as $guide) {
            $card = $this->writeGuide($guide);
            $cards = array_merge($cards, $card);
        }
        if (!$single) {
            $this->writeGuidesPage($site->guidesPage, $cards);
            // a sidebar file with only a comment: Docsify looks for one beside the Guides page (no 404), and shows no sidebar
            $this->writeFile('_sidebar.md', '<!-- No sidebar on the Guides page: each guide folder has its own _sidebar.md -->');
        }
        // The top navbar: empty on the Guides page (each guide has its own), or a single guide's menu pages.
        // A Starter Kit always loads it, and an empty one takes no space; Docsify-This only loads it with loadNavbar.
        $this->writeFile('_navbar.md', implode("\n", $this->siteMenuLinks));
    }

    // Writes one guide and returns its card for the Guides page
    private function writeGuide(Page $guide): array
    {
        $guideSlug = $guide->slug();
        // where the guide's files go, and its home page
        $top = $guideSlug . '/';
        $homeFile = $guideSlug . '/README.md';
        if ($this->single) {
            $top = '';
            $homeFile = 'home.md';
        }
        $this->findGlossary($guide, $top);

        // reading pages have no back link: the guide's name at the top of the sidebar links to its home (in a single
        // guide, the site's name does), as Docsify always shows the sidebar beside them (see writeSidebar)
        if (!$this->single) {
            $this->copyImages($guide, $guideSlug);
        } else {
            // a single guide's own folder only holds its cover, shown on its home page (home.md, at the top)
            $this->copyImages($guide, '');
        }

        $sections = [];
        $sectionNumber = 0;
        foreach ($guide->children as $section) {
            $sectionNumber++;
            $number = $section->setting('section_number');
            if ($number === '') {
                $number = (string)$sectionNumber;
            }
            $label = self::sectionLabel($guide, $section, $number);
            $start = self::redirectPage($section);
            $sections[] = ['page' => $section, 'label' => $label, 'start' => $start];
            $this->writeSection($section, $guideSlug, $top, $label, $start);
        }

        $menuLinks = $this->writeMenuPages($guide, $top, $homeFile);
        $this->writeGuideHome($guide, $homeFile, $top, $sections);
        $this->writeSidebar($guide, $top, $sections);
        if ($this->single && count($menuLinks) > 0) {
            // the site's name first, linked home: Docsify-This shows it at the top of pages without a sidebar, and
            // on phones, where the sidebar starts closed
            $name = $guide->setting('menu') !== '' ? $guide->setting('menu') : $guide->title();
            $this->siteMenuLinks = array_merge(['- [' . $name . '](home.md ":class=site-title")'], $menuLinks);
        } elseif ($this->single) {
            // a single guide without menu pages has no navbar, as in the Docsify-This Guide Site template: the guide's
            // name is on its home page's cover and above its sidebar, so a site title would only repeat it. The file
            // stays, but empty – Docsify-This then hides the navbar (even a comment in it would show an empty bar)
            $this->siteMenuLinks = [];
        } else {
            // the site title links to the Guides page (Docsify-This shows it at the top of pages without a sidebar,
            // and on phones), then the guide's own menu pages
            $navbar = array_merge(['- [' . $this->siteTitle . '](home.md ":class=site-title")'], $menuLinks);
            $this->writeFile($guideSlug . '/_navbar.md', implode("\n", $navbar));
        }
        return $this->guideCard($guide);
    }

    // The label shown above a section's title, as the theme shows it (for example "Chapter 2"): the section's own
    // Section Label (an empty one hides it), or else the guide's Section Label with the section's number. Show Section
    // Label turned off, or a section set to numbered: false, hides the guide's label.
    private static function sectionLabel(Page $guide, Page $section, string $number): string
    {
        if (array_key_exists('section_label', $section->meta)) {
            return $section->setting('section_label');
        }
        $label = $guide->setting('section_label');
        if (!array_key_exists('section_label', $guide->meta)) {
            // a guide copied from Helios Open Reader may turn on Show Section Label without a label: "Section"
            $label = ($guide->meta['show_section_label'] ?? false) ? 'Section' : '';
        }
        // (Show Section Label off is any false-like value, as in the theme; numbered must be false itself)
        $hidden = !($guide->meta['show_section_label'] ?? true) || ($section->meta['numbered'] ?? true) === false;
        if ($label === '' || $hidden) {
            return '';
        }
        return $label . ' ' . $number;
    }

    // A section with no text of its own that redirects to one of its pages (as the Pressbooks Converter's parts do):
    // that page, which its card, Start Reading and the sidebar then lead to, since a Docsify page can't redirect.
    // Returns null for any other section.
    private static function redirectPage(Page $section): ?Page
    {
        $redirect = rtrim($section->setting('redirect'), '/');
        if ($redirect === '' || trim($section->body) !== '' || $section->setting('learning_objectives') !== '') {
            return null;
        }
        foreach ($section->children as $page) {
            if (str_ends_with($redirect, '/' . $section->slug() . '/' . $page->slug())) {
                return $page;
            }
        }
        return null;
    }

    // A section's page (its title, section label, Learning Objectives and text), then each of its pages. A section
    // that redirects to one of its pages lists its pages instead, in case someone opens it.
    private function writeSection(Page $section, string $guideSlug, string $top, string $label, ?Page $start): void
    {
        $sectionFolder = $top . $section->slug();
        $this->copyImages($section, $sectionFolder);
        foreach ($section->children as $page) {
            // a page's images go in its section's folder, beside the page
            $this->copyImages($page, $sectionFolder);
        }

        $sectionFile = $sectionFolder . '/README.md';
        $head = ['# ' . $section->title()];
        if ($label !== '') {
            // at full size, as in the Docsify-This templates and the Starter Kits
            $head = array_merge($head, ['', $label]);
        }
        $body = $this->convert($section->body, '/' . $guideSlug . '/' . $section->slug(), $sectionFile);
        $objectives = $section->setting('learning_objectives');
        if ($objectives !== '') {
            $head[] = '';
            $head[] = Callouts::quote('TIP', 'Learning Objectives', $objectives);
            $body = Callouts::tipsToNotes($body);
        }
        if ($start !== null) {
            $head[] = '';
            foreach ($section->children as $page) {
                $pageLink = Helpers::fromFolder($sectionFolder . '/' . $page->slug() . '.md', true);
                $head[] = '- [' . $page->title() . '](' . $pageLink . ')';
            }
        }
        $gravPath = '/' . $guideSlug . '/' . $section->slug();
        $this->writePage($sectionFile, $section, $head, $body, $this->sideBox($section, $gravPath, $sectionFile));

        foreach ($section->children as $page) {
            $pageFile = $sectionFolder . '/' . $page->slug() . '.md';
            $gravPath = '/' . $guideSlug . '/' . $section->slug() . '/' . $page->slug();
            $head = ['# ' . $page->title()];
            $body = $this->convert($page->body, $gravPath, $pageFile);
            $this->writePage($pageFile, $page, $head, $body, $this->sideBox($page, $gravPath, $pageFile));
        }
    }

    // A section's or page's Side Box (shown beside its text in Grav) as a NOTE at the end of the page, as on the
    // hand-built Share, Move, Reuse site; [] when it has none
    private function sideBox(Page $page, string $gravPath, string $where): array
    {
        $sideBox = $page->setting('side_box');
        if ($sideBox === '') {
            return [];
        }
        return ["> [!NOTE]\n" . Helpers::quoteLines(trim($this->convert($sideBox, $gravPath, $where)))];
    }

    // Guide menu pages (Topics, Resources …) are outside the reading order: they hide the sidebar, link back to
    // the guide home (even in a single guide, since the site's name is hidden with the sidebar), and are linked
    // from the navbar, in Grav's menu_order. Returns those navbar links.
    private function writeMenuPages(Page $guide, string $top, string $homeFile): array
    {
        $menuPages = $guide->menuPages;
        usort($menuPages, [$this, 'compareMenuOrder']);

        $navbarLinks = [];
        foreach ($menuPages as $page) {
            $path = $top . $page->slug() . '.md';
            $body = trim($this->convert($page->body, '/' . $guide->slug() . '/' . $page->slug(), $path));
            $head = ['[← ' . $guide->title() . '](' . Helpers::fromFolder($homeFile, !$this->single) . ')'];
            // the page's own text often starts with its title; if not, it's added
            if (!str_starts_with($body, '# ')) {
                $head[] = '';
                $head[] = '# ' . $page->title();
            }
            $hideSidebar = ['<!-- Hides the sidebar on this page -->', self::HIDE_SIDEBAR];
            [$body, $glossary] = $this->withGlossary($page, $body, []);
            if (count($glossary) > 0) {
                $hideSidebar = array_merge($glossary, [''], $hideSidebar);
            }
            $lines = array_merge([Helpers::gravNote($page), ''], Helpers::generated($head), ['', $body, ''], Helpers::generated($hideSidebar));
            $this->writeFile($path, implode("\n", $lines));
            $navbarLinks[] = '- [' . $page->title() . '](' . $path . ')';
        }
        return $navbarLinks;
    }

    private function compareMenuOrder(Page $a, Page $b): int
    {
        return $this->menuOrder($a) <=> $this->menuOrder($b);
    }

    // Grav's menu_order setting; pages without one come last
    private function menuOrder(Page $page): int
    {
        if (!isset($page->meta['menu_order'])) {
            return 99;
        }
        return (int)$page->meta['menu_order'];
    }

    // The guide's home page: title and subtitle, its intro, Start Reading and a card for each section, the rest
    // of its text (below === in Grav), then the Side Box, authors line and attribution
    private function writeGuideHome(Page $guide, string $homeFile, string $top, array $sections): void
    {
        $gravPath = '/' . $guide->slug();
        $textParts = $this->splitAtSeparator($guide->body);

        // the parts any card has (image, label, description): a card without one gets an empty space for it, so all
        // the cards have the same parts and Docsify-This lines them up in rows
        $parts = ['image' => false, 'label' => false, 'description' => false];
        foreach ($sections as $item) {
            $parts['image'] = $parts['image'] || $item['page']->setting('image') !== '';
            $parts['label'] = $parts['label'] || $item['label'] !== '';
            $parts['description'] = $parts['description'] || $item['page']->setting('description') !== '';
        }
        // the sections as the guide's Cards Per Row setting shows them in Grav: one per row (a guide's default) is
        // Docsify-This's card-list-rows (one card per row), as long as no section has an image; two per row is
        // card-list, and three per row card-list-sections
        $perRow = (int)($guide->meta['cards_per_row'] ?? 1);
        $listStart = ['<div class="card-list-sections">', ''];
        $listEnd = ['</div>'];
        $rows = $perRow === 1 && !$parts['image'];
        if ($rows) {
            $listStart = ['<div class="card-list-rows">', ''];
        } elseif ($perRow === 2) {
            $listStart = ['<div class="card-list">', ''];
        }
        $cards = [];
        if ($rows) {
            // cards one under the other don't need to line up, so no empty spaces
            $parts = ['image' => false, 'label' => false, 'description' => false];
        }
        foreach ($sections as $item) {
            $cards = array_merge($cards, $this->sectionCard($item['page'], $item['label'], $top, $parts, $item['start']));
        }
        $firstPage = $homeFile;
        if (count($sections) > 0) {
            $firstPage = $top . $sections[0]['page']->slug() . '/README.md';
            if ($sections[0]['start'] !== null) {
                $firstPage = $top . $sections[0]['page']->slug() . '/' . $sections[0]['start']->slug() . '.md';
            }
        }
        // a guide in its own folder: the link starts with /, so it also works on GitHub
        $firstPage = Helpers::fromFolder($firstPage, !$this->single);

        // a single guide's home is the site's home page, so it also keeps the original site's address
        $noteExtra = [];
        if ($this->single && $this->siteUrl !== '') {
            $noteExtra = ['site' => $this->siteUrl];
        }
        $startReading = '[Start Reading](' . $firstPage . ' ":class=button :class=primary")';
        // with a small cover (Grav's default), the cover, details and Start Reading go at the top, as in Grav
        $smallCover = $guide->setting('cover_image') !== '' && $guide->setting('cover_image_layout') !== 'large';

        $lines = [Helpers::gravNote($guide, $noteExtra), ''];
        if ($smallCover) {
            $lines = array_merge($lines, Helpers::generated($this->guideCover($guide, $startReading)), ['']);
        } else {
            $head = ['# ' . $guide->title(), '', $guide->setting('subtitle')];
            if ($guide->setting('cover_image') !== '') {
                // a large cover goes across the page, above the title
                $head = array_merge([$this->coverImage($guide), ''], $head);
            }
            $lines = array_merge($lines, Helpers::generated($head), ['']);
        }
        $lines[] = trim($this->convert($textParts['above'], $gravPath, $homeFile));
        $lines[] = '';

        $startAndCards = $listStart;
        if (!$smallCover) {
            $startAndCards = array_merge([$startReading, ''], $startAndCards);
        }
        $startAndCards = array_merge($startAndCards, $cards, $listEnd);
        // Start Reading again after the cards, as a bold link with an arrow, as Grav shows it
        if (count($sections) > 0) {
            $startAndCards = array_merge($startAndCards, ['', self::startReadingBelow($firstPage)]);
        }
        $lines = array_merge($lines, Helpers::generated($startAndCards), ['']);

        if (trim($textParts['below']) !== '') {
            // marks where Grav's === line was, for the way back
            $lines[] = '<!-- grav:=== -->';
            $lines[] = '';
            $lines[] = trim($this->convert($textParts['below'], $gravPath, $homeFile));
            $lines[] = '';
        }
        $lines = array_merge($lines, Helpers::generated($this->guideHomeFooter($guide, $gravPath, $homeFile, !$smallCover)));
        $this->writeFile($homeFile, implode("\n", $lines));
    }

    // A small cover with the guide's details beside it (the guide-cover class in Docsify-This and the Starter Kits):
    // the cover, then the title, subtitle, author(s), edition, licence and Start Reading
    private function guideCover(Page $guide, string $startReading): array
    {
        $details = ['# ' . $guide->title(), ''];
        foreach ([$guide->setting('subtitle'), $guide->setting('authors')] as $line) {
            if ($line !== '') {
                $details[] = $line;
                $details[] = '';
            }
        }
        $edition = $guide->setting('edition') . $this->updated($guide);
        if ($edition !== '') {
            $details[] = '<small>' . ltrim($edition, ' ·') . '</small>';
            $details[] = '';
        }
        // the licence as a pill, linked to the licence when there's an address
        $license = $guide->setting('license');
        if ($license !== '' && $guide->setting('license_url') !== '') {
            $details[] = '[' . $license . '](' . $guide->setting('license_url') . ' ":class=navpill")';
            $details[] = '';
        } elseif ($license !== '') {
            $details[] = '<span class="navpill">' . $license . '</span>';
            $details[] = '';
        }
        $details[] = $startReading;
        // a portrait cover (taller than wide, such as a book cover) is shown whole, as Grav does
        $class = 'guide-cover';
        $coverFile = $this->pagesDir . '/' . $guide->folder . '/' . $guide->setting('cover_image');
        $size = is_file($coverFile) ? getimagesize($coverFile) : false;
        if ($size !== false && $size[1] > $size[0]) {
            $class .= ' guide-cover-portrait';
        }
        return array_merge(['<div class="' . $class . '">', '', $this->coverImage($guide), '', '<div>', ''], $details, ['', '</div>', '', '</div>']);
    }

    // The cover image, from the guide's own folder (beside its home page). Without alt text, "<title> cover", as in Grav.
    private function coverImage(Page $guide): string
    {
        $alt = $guide->setting('cover_image_alt');
        if ($alt === '') {
            $alt = $guide->title() . ' cover';
        }
        return '![' . $alt . '](' . $guide->setting('cover_image') . ' ":no-zoom")';
    }

    // A section's card on the guide home: image, label, title, description (an empty <p></p> for a part another card
    // has and this one doesn't)
    private function sectionCard(Page $section, string $label, string $top, array $parts, ?Page $start): array
    {
        // the card leads to the section's page, or to the page it redirects to
        $link = $top . $section->slug() . '/README';
        if ($start !== null) {
            $link = $top . $section->slug() . '/' . $start->slug();
        }
        $card = ['<div class="card">', ''];
        $image = $section->setting('image');
        if ($image !== '') {
            // a decorative image (empty alt text, no link), read from the page's own folder: the card's one link is
            // its title, which Docsify stretches over the whole card, so a screen reader announces it once
            $card[] = '![](' . $section->slug() . '/' . $image . ' ":no-zoom")';
            $card[] = '';
        } elseif ($parts['image']) {
            $card = array_merge($card, ['<p></p>', '']);
        }
        if ($label !== '') {
            $card = array_merge($card, [$label, '']);
        } elseif ($parts['label']) {
            $card = array_merge($card, ['<p></p>', '']);
        }
        $card[] = Helpers::cardTitle($link, $section->title(), $this->single);
        $card[] = '';
        $description = $section->setting('description');
        if ($description !== '') {
            $card[] = $description;
            $card[] = '';
        } elseif ($parts['description']) {
            $card = array_merge($card, ['<p></p>', '']);
        }
        $card[] = '</div>';
        $card[] = '';
        return $card;
    }

    // The Side Box, the authors line (unless it's already in the cover) and the attribution, then the line that hides the sidebar
    private function guideHomeFooter(Page $guide, string $gravPath, string $homeFile, bool $authorsLine): array
    {
        $footer = [];
        $sideBox = $guide->setting('side_box');
        if ($sideBox !== '') {
            // the Side Box invites readers to act, so a TIP
            $footer[] = "> [!TIP]\n" . Helpers::quoteLines(trim($this->convert($sideBox, $gravPath, $homeFile)));
            $footer[] = '';
        }
        // no authors line when the guide has neither authors nor edition (it would be just " · ")
        $hasAuthorsLine = $guide->setting('authors') !== '' || $guide->setting('edition') !== '';
        if ($authorsLine && $hasAuthorsLine) {
            $footer[] = $guide->setting('authors') . ' · ' . $guide->setting('edition') . $this->updated($guide);
            $footer[] = '';
        }
        $attribution = $guide->setting('attribution_text');
        if ($attribution !== '') {
            $footer[] = $attribution;
            $footer[] = '';
        }
        $footer[] = '<!-- Hides the sidebar on this page -->';
        $footer[] = self::HIDE_SIDEBAR;
        return $footer;
    }

    // The reading order: a group for each section with its pages (the guide home isn't listed: it hides the
    // sidebar, and every page leads back to it). Docsify can open and close a group only when it's at the top
    // level with a plain-text title, so the section's own page is the group's first link, as Overview.
    private function writeSidebar(Page $guide, string $top, array $sections): void
    {
        $lines = [];
        if (!$this->single) {
            // the guide's name first, in bold and linked to its home – the way back from any of its pages (not a list
            // item, so it isn't read as a section). A single guide's site name is its name, and already links home.
            $lines = ['[**' . $guide->title() . '**](' . $top . 'README.md)', ''];
        }
        foreach ($sections as $item) {
            $section = $item['page'];
            $sectionLink = $top . $section->slug() . '/README.md';
            if (count($section->children) === 0) {
                $lines[] = '- [' . $section->title() . '](' . $sectionLink . ')';
                continue;
            }
            $lines[] = '- ' . $section->title();
            // (a section that redirects to one of its pages has no Overview: its pages are listed on their own)
            if ($item['start'] === null) {
                $lines[] = '  - [' . Helpers::overviewLabel($section->title()) . '](' . $sectionLink . ')';
            }
            foreach ($section->children as $page) {
                $lines[] = '  - [' . $page->title() . '](' . $top . $section->slug() . '/' . $page->slug() . '.md)';
            }
        }
        $this->writeFile($top . '_sidebar.md', implode("\n", $lines));
    }

    // A guide's card on the Guides page: cover image, title (with any badge), subtitle, authors and edition
    private function guideCard(Page $guide): array
    {
        $guideSlug = $guide->slug();
        $image = '';
        if ($guide->setting('cover_image') !== '') {
            $image = $guideSlug . '/' . $guide->setting('cover_image');
        }
        $alt = $guide->setting('cover_image_alt');
        if ($alt === '') {
            $alt = $guide->title();
        }
        // a badge such as "Draft" goes inside the title, so the cards still line up
        $badge = '';
        if ($guide->setting('badge_label') !== '') {
            $badge = ' <span class="badge">' . $guide->setting('badge_label') . '</span>';
        }
        $text = [$guide->setting('subtitle'), '<small>' . $guide->setting('authors') . '<br>' . $guide->setting('edition') . $this->updated($guide) . '</small>'];
        return Helpers::card($guideSlug . '/README', $guide->title(), $image, $alt, $text, $badge, true, false);
    }

    // The Guides page (Grav's Guides List page), with a card for each guide
    private function writeGuidesPage(?Page $page, array $cards): void
    {
        if ($page === null) {
            $page = new Page('guides', '00.guides', 'reader-list.md');
        }
        $gravPath = '/' . $page->slug();
        $textParts = $this->splitAtSeparator($page->body);
        $title = $page->setting('title');
        if ($title === '') {
            $title = 'Guides';
        }

        // the original site's address is kept with the home page, so links to it can go back to Grav's own form
        $noteExtra = [];
        if ($this->siteUrl !== '') {
            $noteExtra = ['site' => $this->siteUrl];
        }
        $lines = [Helpers::gravNote($page, $noteExtra), ''];
        $lines = array_merge($lines, Helpers::generated(['# ' . $title, '', $page->setting('subtitle')]), ['']);
        $lines[] = trim($this->convert($textParts['above'], $gravPath, 'home.md'));
        $lines[] = '';
        // three cards to a row (card-list-guides) when the Grav page asks for three; two (card-list) otherwise
        $cardList = 'card-list';
        if ($page->setting('cards_per_row') === '3') {
            $cardList = 'card-list card-list-guides';
        }
        $cardLines = array_merge(['<div class="' . $cardList . '">', ''], $cards, ['</div>']);
        $lines = array_merge($lines, Helpers::generated($cardLines), ['']);
        if (trim($textParts['below']) !== '') {
            $lines[] = '<!-- grav:=== -->';
            $lines[] = '';
            $lines[] = trim($this->convert($textParts['below'], $gravPath, 'home.md'));
            $lines[] = '';
        }
        // the subtitle (the line below the title) is shown larger and grey, as in Grav, with the same rule that hides
        // the sidebar (reading the page back into Grav removes this whole line)
        $styleComment = '<!-- Hides the sidebar on this page -->';
        $styleLine = self::HIDE_SIDEBAR;
        if ($page->setting('subtitle') !== '') {
            $styleComment = '<!-- Hides the sidebar on this page, and shows the line below the title as a subtitle, as on the Grav version -->';
            $styleLine = str_replace('</style>', ' ' . self::SUBTITLE_STYLE . '</style>', $styleLine);
        }
        $lines = array_merge($lines, Helpers::generated([$styleComment, $styleLine]));
        $this->writeFile('home.md', implode("\n", $lines));
    }

    // $foot: anything the converter adds after the page's text (such as its Side Box)
    private function writePage(string $path, Page $page, array $head, string $body, array $foot = []): void
    {
        [$body, $foot] = $this->withGlossary($page, $body, $foot);
        $lines = array_merge([Helpers::gravNote($page), ''], Helpers::generated($head), ['', trim($body)]);
        if (count($foot) > 0) {
            $lines[] = '';
            $lines = array_merge($lines, Helpers::generated($foot));
        }
        $this->writeFile($path, implode("\n", $lines));
    }

    // A Glossary page (one with glossary: in its settings) shows its terms, as Grav does: where [glossary] is in its
    // text, or else at the end (added to $foot). Each term in bold, its definition on the next line, A to Z as in
    // Grav. Returns [the page's text, its foot].
    private function withGlossary(Page $page, string $body, array $foot): array
    {
        $entries = [];
        foreach ((array)($page->meta['glossary'] ?? []) as $item) {
            $term = trim((string)($item['term'] ?? ''));
            if ($term !== '') {
                $entries[] = ['term' => $term, 'definition' => trim((string)($item['definition'] ?? ''))];
            }
        }
        if (count($entries) === 0) {
            return [$body, $foot];
        }
        usort($entries, fn($a, $b) => strnatcasecmp($a['term'], $b['term']));
        // a long glossary gets an A–Z letter bar and a heading for each letter, as Grav shows it (HTML headings, so
        // each keeps the id its letter links to and stays out of the sidebar)
        $byLetter = count($entries) > 26;
        $list = [];
        $letters = [];
        foreach ($entries as $entry) {
            $letter = strtoupper(substr($entry['term'], 0, 1));
            if ($byLetter && ctype_alpha($letter) && !in_array($letter, $letters, true)) {
                $list[] = '<h2 id="' . strtolower($letter) . '">' . $letter . '</h2>';
                $list[] = '';
                $letters[] = $letter;
            }
            // an anchor named as in Grav (glossary-pressbooks), so a term's link can open at its entry
            $list[] = '<a id="' . Glossary::anchor($entry['term']) . '"></a>**' . $entry['term'] . '**<br>';
            $list[] = $entry['definition'];
            $list[] = '';
        }
        $list = array_slice($list, 0, -1);
        if ($byLetter) {
            $list = array_merge([Callouts::letterBar($letters), ''], $list);
        }
        // in the text, the list has its own generated marks (the foot is marked as a whole)
        $marker = '<!-- grav: [glossary] -->';
        if (str_contains($body, $marker)) {
            return [str_replace($marker, $marker . "\n\n" . implode("\n", Helpers::generated($list)), $body), $foot];
        }
        return [$body, array_merge($list, count($foot) > 0 ? [''] : [], $foot)];
    }

    // The guide's Glossary page: its first menu page with glossary: in its settings. Its terms link to it.
    private function findGlossary(Page $guide, string $top): void
    {
        $this->glossary = [];
        $this->glossaryFile = '';
        foreach ($guide->menuPages as $page) {
            $entries = Glossary::entries($page);
            if (count($entries) > 0) {
                $this->glossary = $entries;
                $this->glossaryFile = $top . $page->slug() . '.md';
                return;
            }
        }
    }

    // Grav Markdown → Docsify Markdown: callouts, links between guide pages, then links that only work in Grav.
    // $gravPath: the page's address on the Grav site; $where: the Docsify file, for warnings
    private function convert(string $markdown, string $gravPath, string $where): string
    {
        $codeParts = [];
        $markdown = Helpers::hideCode($markdown, $codeParts);
        $markdown = Embeds::hideInjectLines($markdown, $codeParts, $this->siteUrl !== '');
        $markdown = Embeds::toDocsify($markdown);
        $markdown = Callouts::toDocsify($markdown);
        $markdown = Links::newTabToDocsify($markdown);
        $this->recordKeptLinks($where, $markdown);
        $inFolder = str_contains($where, '/');
        $markdown = Links::toDocsify($markdown, $this->guideSlugs, $this->single, $inFolder);
        if ($this->glossaryFile !== '') {
            $glossaryLink = Helpers::fromFolder($this->glossaryFile, $inFolder);
            $markdown = Glossary::termsToLinks($markdown, $this->glossary, $glossaryLink);
        }
        foreach (Callouts::unknownShortcodes($markdown) as $name) {
            $this->warnings[] = $where . ': the [' . $name . '] shortcode only works in Grav (left as it is)';
        }
        $markdown = Links::gravOnly($markdown, $gravPath, $this->siteUrl, $where, $this->warnings);
        return Helpers::showCode($markdown, $codeParts);
    }

    // A guide home's text is split by a line of === : the intro above the section cards, the rest below them
    private function splitAtSeparator(string $body): array
    {
        $parts = preg_split('/^===[ \t]*$/m', $body, 2);
        $below = '';
        if (isset($parts[1])) {
            $below = $parts[1];
        }
        return ['above' => $parts[0], 'below' => $below];
    }

    private function updated(Page $guide): string
    {
        $lastUpdated = $guide->setting('last_updated');
        if ($lastUpdated === '') {
            return '';
        }
        return ' · Updated ' . Helpers::shortDate($lastUpdated);
    }

    private function copyImages(Page $page, string $toFolder): void
    {
        foreach ($page->images as $image) {
            $target = $this->out . '/' . ltrim($toFolder . '/' . $image, '/');
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0700, true);
            }
            copy($this->pagesDir . '/' . $page->folder . '/' . $image, $target);
        }
    }

    private function writeFile(string $path, string $text): void
    {
        $text = $this->withKeptLinks($path, $text);
        $target = $this->out . '/' . $path;
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0700, true);
        }
        file_put_contents($target, rtrim($text) . "\n");
    }

    // Notes the page's links to a Markdown version (/guide/page.md), kept as they are when there's no site address,
    // so the way back doesn't read them as links between pages
    private function recordKeptLinks(string $where, string $markdown): void
    {
        if ($this->siteUrl !== '') {
            return; // with a site address they become full links to it
        }
        foreach (Links::markdownVersionLinks($markdown) as $link) {
            if (!in_array($link, $this->keptLinks[$where] ?? [], true)) {
                $this->keptLinks[$where][] = $link;
            }
        }
    }

    // Adds the page's kept links to its hidden note: {"file":"…"} → {"file":"…","kept":["/guide/intro.md"]}
    // A page in a folder always gets the list (maybe empty): it tells the way back that the page's / links are
    // links between pages. Done as the file is saved, as some notes are written before the text is converted.
    private function withKeptLinks(string $path, string $text): string
    {
        $start = '<!-- grav: ';
        $inFolder = str_contains($path, '/');
        if (!str_starts_with($text, $start) || (!$inFolder && !isset($this->keptLinks[$path]))) {
            return $text;
        }
        // the note's first line is its JSON
        $lineEnd = strpos($text, "\n");
        if ($lineEnd === false) {
            return $text;
        }
        $note = json_decode(substr($text, strlen($start), $lineEnd - strlen($start)), true);
        if (!is_array($note)) {
            return $text;
        }
        $note['kept'] = $this->keptLinks[$path] ?? [];
        // as in Helpers::gravNote, --> is written as --\u003e so it can't end the comment
        $json = str_replace('-->', '--\\u003e', json_encode($note, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $start . $json . substr($text, $lineEnd);
    }
}
