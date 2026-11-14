<?php
namespace OPG;

// Writes an Open Course Space course as a Docsify course, laid out like the Docsify Open Course Starter Kit and shown
// like the course in Grav: home.md (with the course's header image and name), a page per module and menu page,
// images/, _sidebar.md (modules and LMS links), _navbar.md and any _footer.md. Hidden notes keep what's needed to go back
// to the same Grav pages (see CourseDocsifyReader).
class CourseDocsifyWriter
{
    // Links that only work on the Grav site, and anything else worth checking (for conversion-notes.txt)
    public array $warnings = [];
    // the header image's address in the course's images/ ('' when it has none), for its card on the Courses page
    public string $bannerImage = '';

    private string $pagesDir;
    private string $out;
    private string $siteUrl;
    // each converted page's Grav address => its Docsify file
    private array $addresses = [];
    // image name in images/ => the file it was copied from
    private array $copiedImages = [];
    // the links converted since the last hidden note was written, as [Docsify address, Grav address as written]
    private array $pageLinks = [];
    // the course's address on the Grav site, e.g. '/cpt363-basic' (its pages' addresses start with it)
    private string $course = '';
    // in a site of several courses, the course's folder in Docsify, e.g. 'cpt363-basic/': links between pages start at
    // the site's top, so they start with it ('' for a course on its own)
    private string $top = '';

    public function write(Site $site, string $pagesDir, string $out, string $siteUrl = '', string $top = ''): void
    {
        $this->top = $top;
        $this->bannerImage = '';
        $this->pagesDir = $pagesDir;
        $this->out = $out;
        $this->siteUrl = $siteUrl;
        $this->warnings = [];
        $this->copiedImages = [];
        $modulesPage = $site->modulesPage;
        $this->course = '/' . Helpers::slugFromFolder($site->courseFolder);

        // every page's address first, so links between them are converted. The Modules page has no page of its own
        // (the sidebar lists the modules), so links to it go to the course home.
        $modulesPath = $this->course . '/' . $modulesPage->slug();
        $this->addresses = [$this->course => 'home.md', $this->course . '/home' => 'home.md', $modulesPath => 'home.md'];
        foreach ($modulesPage->children as $module) {
            $this->addresses[$modulesPath . '/' . $module->slug()] = $module->slug() . '.md';
        }
        foreach ($site->coursePages as $page) {
            $this->addresses[$this->course . '/' . $page->slug()] = $page->slug() . '.md';
        }

        foreach ($modulesPage->children as $module) {
            $this->writeModule($module, $modulesPath);
        }
        $this->writeHome($site, $modulesPage);

        // the course's name first, as the site's title (Docsify-This shows it on phones, where the sidebar starts closed)
        $navbar = ['- [' . $site->coursePage->title() . '](home.md ":class=site-title")'];
        foreach ($site->coursePages as $page) {
            $this->writeMenuPage($page);
            $navbar[] = '- [' . $page->title() . '](' . $page->slug() . '.md)';
        }
        $sidebarGroups = [];
        if ($site->courseSidebar !== null) {
            $sidebarGroups = $this->writeContact($site->courseSidebar);
            if (is_file($this->out . '/contact.md')) {
                $navbar[] = '- [Contact](contact.md)';
            }
        }
        $this->writeFile('_navbar.md', implode("\n", $navbar));
        if ($this->top !== '') {
            // in a site of several courses, a way back to the Courses page (the site's home page: links start at the
            // top, so it's added after the course's own links get its folder), after the course's name
            $lines = explode("\n", (string)file_get_contents($this->out . '/_navbar.md'));
            $lines = array_merge([$lines[0], '- [All Courses](home.md)'], array_slice($lines, 1));
            file_put_contents($this->out . '/_navbar.md', implode("\n", $lines));
        }
        $this->writeSidebar($site, $sidebarGroups);
        // the course's footer, unless it only credits Grav (then it's kept in the home page's note, see courseNote())
        if ($site->courseFooter !== null && !self::isGravCredit($site->courseFooter)) {
            $footer = trim($this->convert($this->withImagesFolder($site->courseFooter), $this->course, '_footer.md'));
            $this->writeFile('_footer.md', implode("\n", [$this->note($site->courseFooter), '', $footer]));
        }
    }

    // A footer that says the site was built with Grav (as the skeletons' footers do: they link to getgrav.org), which
    // isn't true of the Docsify copy
    private static function isGravCredit(Page $footer): bool
    {
        return str_contains(strtolower($footer->body), 'getgrav.org');
    }

    // A module's page, as Open Course Space shows it: the module's title as its heading, then its header image (with
    // its credit), its description and any learning objectives, then its text
    private function writeModule(Page $module, string $modulesPath): void
    {
        $file = $module->slug() . '.md';
        $gravPath = $modulesPath . '/' . $module->slug();
        $head = ['# ' . $module->title()];
        $headerImage = $this->headerImage($module);
        if ($headerImage !== '') {
            $head[] = '';
            $head[] = '![' . $module->setting('header_image_alt_text') . '](' . $headerImage . ' \':class=banner-image\')';
            if ($module->setting('header_image_credit') !== '') {
                $credit = $module->setting('header_image_credit');
                if ($module->setting('header_image_creditlink') !== '') {
                    $credit = '[' . $credit . '](' . $module->setting('header_image_creditlink') . ')';
                }
                $head[] = '';
                $head[] = '<small>Photo: ' . $credit . '</small>';
            }
        }
        // the description as a ## heading, left out of the sidebar's list of the page's sections
        // ({docsify-ignore}); the text's own copy of it is hidden below (see withoutRepeatedHeading())
        $description = $module->setting('description');
        if ($description !== '') {
            $head[] = '';
            $head[] = '## ' . $description . ' <!-- {docsify-ignore} -->';
        }
        if ($module->setting('learning_objectives') !== '') {
            $head[] = '';
            $head[] = Callouts::quote('TIP', 'Learning Objectives', $module->setting('learning_objectives'));
        }
        $body = $this->withImagesFolder($module);
        if ($description !== '') {
            $body = $this->withoutRepeatedHeading($body, $description);
        }
        $body = $this->convert($body, $gravPath, $file);
        $lines = array_merge([$this->note($module), ''], Helpers::generated($head), ['', trim($body)]);
        $this->writeFile($file, implode("\n", $lines));
    }

    // '## **What is usability?**' at the start of the text, when the description is 'What is usability?', is kept as
    // a hidden note (the description is shown above it). When that was the text's only ## heading, its ### headings
    // move up to ##, so the sidebar lists the page's sections; the note says so, for the way back.
    private function withoutRepeatedHeading(string $body, string $title): string
    {
        $text = ltrim($body, "\n");
        $firstLine = strtok($text, "\n");
        if ($firstLine === false || preg_match('/^(#{1,6})\s+(.+?)\s*$/', $firstLine, $found) !== 1) {
            return $body;
        }
        if (trim($found[2], " *_") !== trim($title, " *_")) {
            return $body;
        }
        $rest = substr($text, strlen($firstLine));
        $codeParts = [];
        $restWithoutCode = Helpers::hideCode($rest, $codeParts);
        if ($found[1] === '##' && preg_match('/^##\s/m', $restWithoutCode) !== 1 && preg_match('/^###\s/m', $restWithoutCode) === 1) {
            $restWithoutCode = preg_replace('/^#(#{2,6}\s)/m', '$1', $restWithoutCode);
            return Embeds::hiddenLine($firstLine, true) . Helpers::showCode($restWithoutCode, $codeParts);
        }
        return Embeds::hiddenLine($firstLine) . $rest;
    }

    // The course home, shown as in Grav: the course's header image and its name, then the home page's own text with
    // each part it shows ([plugin:page-inject](../home/_part)) in its place – this week's reminders (an alert box in
    // Grav) in the Starter Kits' announcement box. Without a home page, the Modules page's title and description.
    // Its hidden note also keeps the course's settings and header image, for the way back.
    private function writeHome(Site $site, Page $modulesPage): void
    {
        $home = $site->courseHome;
        $extra = array_merge($this->siteNote(), $this->courseNote($site));
        $banner = $this->banner($site->headerImage);
        if ($home === null) {
            $head = ['# ' . $modulesPage->title()];
            if ($modulesPage->setting('description') !== '') {
                $head = array_merge($head, ['', $modulesPage->setting('description')]);
            }
            if (trim($modulesPage->body) !== '') {
                $extra['body'] = trim($modulesPage->body);
            }
            $lines = array_merge([$this->note($modulesPage, $extra), ''], Helpers::generated(array_merge($banner, $head)));
            $this->writeFile('home.md', implode("\n", $lines));
            return;
        }
        $parts = [];
        foreach ($home->children as $part) {
            $parts[$part->slug()] = $part;
        }
        // the home page's own text (anything besides its parts) is converted like any other page, a stretch at a time
        $ownText = [];
        $sections = [];
        // the links in the home page's own text, kept apart from each part's links
        $homeLinks = [];
        foreach (explode("\n", $home->body) as $line) {
            if (preg_match('/^\[plugin:page-inject\]\(\.\.\/[^)]*\/([^\/)]+)\)\s*$/', trim($line), $found) === 1 && isset($parts[$found[1]])) {
                $sections[] = $this->ownHomeText($ownText);
                $ownText = [];
                $homeLinks = array_merge($homeLinks, $this->pageLinks);
                $this->pageLinks = [];
                $sections[] = implode("\n", $this->homePart($parts[$found[1]], trim($line)));
                continue;
            }
            $ownText[] = $line;
        }
        $sections[] = $this->ownHomeText($ownText);
        $this->pageLinks = array_merge($homeLinks, $this->pageLinks);
        // the course's name as the page's heading, as Open Course Space shows it. Its note comes last, so it holds the
        // links of its own text.
        $title = $home->title();
        if ($site->coursePage !== null) {
            $title = $site->coursePage->title();
        }
        $head = array_merge([$this->note($home, $extra), ''], Helpers::generated(array_merge($banner, ['# ' . $title])), ['']);
        $this->writeFile('home.md', implode("\n", $head) . implode("\n", $sections));
    }

    // The course's settings (course.md), header image (headerimage/) and footer (footer/), exactly as written, for the
    // home page's note
    private function courseNote(Site $site): array
    {
        $note = ['course' => array_merge(['folder' => $site->courseFolder], self::pageText($site->coursePage))];
        if ($site->headerImage !== null) {
            // each image's address in images/, where the way back finds it
            $sources = [];
            foreach ($site->headerImage->images as $image) {
                $sources[$image] = $this->copyImage($site->headerImage, $image);
            }
            $note['headerimage'] = array_merge(['images' => $sources], self::pageText($site->headerImage));
        }
        if ($site->courseFooter !== null && self::isGravCredit($site->courseFooter)) {
            $note['footer'] = self::pageText($site->courseFooter);
        }
        return $note;
    }

    // A page's front matter and text, with its spacing, as kept in a note
    private static function pageText(Page $page): array
    {
        return array_merge(['yaml' => $page->yaml, 'body' => trim($page->body)], self::spacing($page));
    }

    // The header image as a banner at the top of the home page, as Open Course Space shows it ([] when there's none)
    private function banner(?Page $headerImage): array
    {
        if ($headerImage === null || count($headerImage->images) === 0) {
            return [];
        }
        $images = $headerImage->images;
        sort($images);
        $this->bannerImage = $this->copyImage($headerImage, $images[0]);
        return ['![](' . $this->bannerImage . " ':class=banner-image')", ''];
    }

    // A stretch of the home page's own text (often just the blank lines between its parts), converted
    private function ownHomeText(array $lines): string
    {
        return preg_replace("/\n{3,}/", "\n\n", $this->convert(implode("\n", $lines), $this->course . '/home', 'home.md'));
    }

    // One part of the home page, with its Grav settings and the page-inject line it came from in hidden notes, and a
    // note where it ends (text after it is the home page's own)
    private function homePart(Page $part, string $injectLine): array
    {
        $body = trim($this->convert($this->withImagesFolder($part), $this->course . '/home', 'home.md'));
        $lines = ['', $this->note($part), Embeds::hiddenLine($injectLine), ''];
        if ($part->file === 'alert.md') {
            // an alert box in Grav: the Starter Kits' announcement box (blank lines around the text, so Docsify
            // reads the Markdown inside it)
            $lines = array_merge($lines, Helpers::generated(['<blockquote class="announcement">', '']), ['', $body, ''], Helpers::generated(['', '</blockquote>']));
        } else {
            $lines = array_merge($lines, [$body]);
        }
        return array_merge($lines, ['', '<!-- grav:/part -->', '']);
    }

    // A menu page (Schedule, Topics …): its text, with its title added when the text doesn't start with one
    private function writeMenuPage(Page $page): void
    {
        $file = $page->slug() . '.md';
        $body = trim($this->convert($this->withImagesFolder($page), $this->course . '/' . $page->slug(), $file));
        $lines = [$this->note($page), ''];
        if (!str_starts_with($body, '# ')) {
            $lines = array_merge($lines, Helpers::generated(['# ' . $page->title()]), ['']);
        }
        $lines[] = $body;
        $this->writeFile($file, implode("\n", $lines));
    }

    // The course sidebar, as in the Starter Kits: a section of links only (such as the LMS links) becomes a group in
    // _sidebar.md; the course details (instructor, office hours …) go on a Contact page. Where each group was, the
    // Contact page has a hidden note with the section's layout, so the way back puts it together again.
    // Returns the sidebar groups.
    private function writeContact(Page $sidebarPage): array
    {
        $text = trim($this->convert($this->withImagesFolder($sidebarPage), $this->course . '/sidebar', 'contact.md'));
        $groups = [];
        $contact = [];
        foreach (preg_split('/^(?=#{1,6}\s)/m', $text) as $section) {
            $lines = explode("\n", trim($section));
            $heading = '';
            if (preg_match('/^#{1,6}\s+(.+?)\s*$/', $lines[0], $found) === 1) {
                $heading = $found[1];
                array_shift($lines);
            }
            $links = [];
            $onlyLinks = true;
            foreach ($lines as $line) {
                if (trim($line) === '') {
                    continue;
                }
                // a link on its own line, maybe after an icon: [fa="calendar"][/fa][Calendar](…), which is by now
                // <!-- grav-embed: [fa="calendar"][/fa] -->:fas fa-calendar fa-fw:[Calendar](…)
                $pattern = '/^\s*[-*]?\s*((?:<!-- grav-embed: (\[fa[^\n]*?) -->:fa[srb] fa-[a-z0-9-]+ fa-fw:)?(\[(?:[^\[\]]|\[[^\]]*\])+\]\([^)]+\)))\s*$/';
                if (preg_match($pattern, $line, $found) === 1) {
                    $links[] = ['text' => $found[1], 'icon' => $found[2], 'link' => $found[3]];
                } else {
                    $onlyLinks = false;
                }
            }
            if ($heading !== '' && $onlyLinks && count($links) > 0) {
                $groups[] = '- **' . $heading . '**';
                foreach ($links as $link) {
                    $groups[] = '  - ' . self::withIconImage($link['link'], $link['icon']);
                }
                $contact[] = self::groupNote($section, $heading, $links);
                continue;
            }
            // only blank lines are trimmed, so a line break at a line's end ("  ") stays
            if (trim($section) !== '') {
                $contact[] = trim($section, "\n");
            }
        }
        $hasDetails = false;
        foreach ($contact as $section) {
            if (!str_starts_with($section, '<!-- grav:sidebar-group ')) {
                $hasDetails = true;
            }
        }
        if ($hasDetails) {
            $lines = array_merge([$this->note($sidebarPage), ''], Helpers::generated(['# Contact']), ['', implode("\n\n", $contact)]);
            $this->writeFile('contact.md', implode("\n", $lines));
        }
        return $groups;
    }

    // A sidebar section of links, as a hidden note: the section with its heading and links taken out, e.g.
    // "## {heading}\n{link}  \n{link}", so the way back can fill in the heading and links from _sidebar.md
    // (an icon stays in the layout as its shortcode: "[fa="calendar"][/fa]{link}")
    private static function groupNote(string $section, string $heading, array $links): string
    {
        // only blank lines are trimmed, so a line break at a line's end ("  ") stays
        $layout = trim($section, "\n");
        $headingAt = strpos($layout, $heading);
        $layout = substr($layout, 0, $headingAt) . '{heading}' . substr($layout, $headingAt + strlen($heading));
        foreach ($links as $link) {
            $at = strpos($layout, $link['text']);
            $layout = substr($layout, 0, $at) . $link['icon'] . '{link}' . substr($layout, $at + strlen($link['text']));
        }
        $json = json_encode(['layout' => $layout], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return '<!-- grav:sidebar-group ' . str_replace('-->', '-->', $json) . ' -->';
    }

    // A sidebar link with its icon in front, as the Starter Kits show them: an image from api.iconify.design, which has
    // Font Awesome 4.7's icons under the theme's own names ([fa="calendar-o"] → fa/calendar-o.svg).
    // Example: [Calendar](https://…) → [![Calendar Icon](https://api.iconify.design/fa/calendar.svg?height=16&color=%23808080)Calendar](https://…)
    private static function withIconImage(string $link, string $icon): string
    {
        if (preg_match('/^\[fa=?"?([a-z0-9-]+)/', $icon, $found) !== 1 || preg_match('/^\[(.*)\]\(/s', $link, $text) !== 1) {
            return $link;
        }
        $image = '![' . $text[1] . ' Icon](https://api.iconify.design/fa/' . $found[1] . '.svg?height=16&color=%23808080)';
        return '[' . $image . substr($link, 1);
    }

    // The modules in order, then any link groups from the course sidebar. With a home page, the Modules page's own
    // settings are kept here (it has no Docsify page of its own).
    private function writeSidebar(Site $site, array $groups): void
    {
        $modulesPage = $site->modulesPage;
        $lines = [];
        if ($site->courseHome !== null) {
            $extra = [];
            if (trim($modulesPage->body) !== '') {
                $extra['body'] = trim($modulesPage->body);
            }
            $lines = [Helpers::gravNote($modulesPage, array_merge($extra, self::spacing($modulesPage))), ''];
        }
        if ($this->top !== '') {
            // in a site of several courses, the course's name first, in bold, as for guides – a link to the course's
            // home page, the way back from its pages
            $lines = array_merge($lines, Helpers::generated(['**[' . $site->coursePage->title() . '](home.md)**']), ['']);
        }
        foreach ($modulesPage->children as $module) {
            // hidden or unpublished modules aren't listed
            if (($module->meta['visible'] ?? true) === false || ($module->meta['published'] ?? true) === false) {
                continue;
            }
            $lines[] = '- [' . $module->title() . '](' . $module->slug() . '.md)';
        }
        $this->writeFile('_sidebar.md', implode("\n", array_merge($lines, $groups)));
    }

    // A page's hidden note, with the links converted since the last one (see Links::mapToDocsify) and the page's
    // spacing around its text when it isn't the usual one
    private function note(Page $page, array $extra = []): string
    {
        if (count($this->pageLinks) > 0) {
            $extra['links'] = $this->pageLinks;
        }
        $this->pageLinks = [];
        return Helpers::gravNote($page, array_merge($extra, self::spacing($page)));
    }

    // The blank space before and after a page's text, when it isn't a blank line before and a line end after
    private static function spacing(Page $page): array
    {
        $text = trim($page->body);
        $before = $page->body;
        $after = '';
        if ($text !== '') {
            $before = substr($page->body, 0, strpos($page->body, $text));
            $after = substr($page->body, strpos($page->body, $text) + strlen($text));
        }
        $spacing = [];
        if ($before !== "\n") {
            $spacing['before'] = $before;
        }
        if ($after !== "\n") {
            $spacing['after'] = $after;
        }
        return $spacing;
    }

    // The original site's address, kept with the home page (as for guides)
    private function siteNote(): array
    {
        if ($this->siteUrl === '') {
            return [];
        }
        return ['site' => $this->siteUrl];
    }

    // The header image Open Course Space shows for a module (header_image: true) or a week (unless header_image: false):
    // header_image_file, or the page's first image. Returns its address in images/, or '' when there isn't one.
    private function headerImage(Page $page): string
    {
        $isShown = !empty($page->meta['header_image']);
        // a week (item.md) shows its first image unless it's turned off
        if ($page->file === 'item.md') {
            $isShown = true;
            if (isset($page->meta['header_image']) && $page->meta['header_image'] === false) {
                $isShown = false;
            }
        }
        if (!$isShown || count($page->images) === 0) {
            return '';
        }
        $file = $page->setting('header_image_file');
        $images = $page->images;
        sort($images);
        if ($file === '' || !in_array(basename($file), $images, true)) {
            $file = $images[0];
        }
        return $this->copyImage($page, basename($file));
    }

    // The page's text with its own images pointing to images/ (the images are copied there)
    private function withImagesFolder(Page $page): string
    {
        $body = $page->body;
        foreach ($page->images as $image) {
            if (!str_contains($body, $image)) {
                continue;
            }
            $address = $this->copyImage($page, $image);
            // ![alt](image.jpg …) and image.jpg?options – only links to the file itself, not to other folders
            $body = preg_replace('/(!\[[^\]]*\]\()' . preg_quote($image, '/') . '(?=[)?\s])/', '$1' . $address, $body);
        }
        return $body;
    }

    // Copies a page's image into images/ (named after the page as well when another page has an image of the same
    // name) and returns its address there
    private function copyImage(Page $page, string $image): string
    {
        $source = $this->pagesDir . '/' . $page->folder . '/' . $image;
        $name = $image;
        if (isset($this->copiedImages[$name]) && $this->copiedImages[$name] !== $source) {
            $name = $page->slug() . '-' . $image;
        }
        if (!isset($this->copiedImages[$name])) {
            $target = $this->out . '/images/' . $name;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0700, true);
            }
            copy($source, $target);
            $this->copiedImages[$name] = $source;
        }
        return 'images/' . $name;
    }

    // In a site of several courses, links to the course's pages start at the site's top: (module-01.md) →
    // (cpt363-basic/module-01.md). Images stay as they are (Docsify reads them from the page's own folder).
    private function withTop(string $text): string
    {
        $files = array_unique(array_merge(array_values($this->addresses), ['contact.md']));
        return preg_replace_callback('/\]\(([A-Za-z0-9_-]+\.md)(?=[)?\s])/', function ($match) use ($files) {
            if (!in_array($match[1], $files, true)) {
                return $match[0];
            }
            return '](' . $this->top . $match[1];
        }, $text);
    }

    // A site of several courses: the Courses page as the site's home page – its text, then a card for each course
    // (its header image, name and description), as Open Course Space shows them. $courses: each course's Site, with
    // its header image's address in its folder (CourseDocsifyWriter::$bannerImage) as $courseBanners[folder].
    public static function writeCoursesPage(Page $page, array $courses, array $courseBanners, string $out, string $siteUrl = ''): void
    {
        $extra = [];
        if ($siteUrl !== '') {
            $extra['site'] = $siteUrl;
        }
        $title = $page->title();
        $lines = [Helpers::gravNote($page, array_merge($extra, self::spacing($page))), ''];
        $lines = array_merge($lines, Helpers::generated(['# ' . $title]), ['']);
        $codeParts = [];
        $text = Helpers::hideCode($page->body, $codeParts);
        $text = Embeds::toDocsify($text);
        $text = Callouts::toDocsify($text);
        $text = Links::newTabToDocsify($text);
        $lines[] = trim(Helpers::showCode($text, $codeParts));
        $lines[] = '';
        $cards = ['<div class="card-list card-list-guides">', ''];
        foreach ($courses as $course) {
            $banner = '';
            if (isset($courseBanners[$course->courseFolder])) {
                $banner = $courseBanners[$course->courseFolder];
            }
            $cards = array_merge($cards, self::courseCard($course, $banner));
        }
        $cards[] = '</div>';
        $lines = array_merge($lines, Helpers::generated($cards), ['']);
        $lines = array_merge($lines, Helpers::generated(['<!-- Hides the sidebar on this page -->', DocsifyWriter::HIDE_SIDEBAR]));
        file_put_contents($out . '/home.md', implode("\n", $lines) . "\n");
        // a sidebar file with only a comment: Docsify looks for one beside the Courses page, and shows no sidebar
        file_put_contents($out . '/_sidebar.md', "<!-- No sidebar on the Courses page: each course folder has its own _sidebar.md -->\n");
        // an empty navbar (each course folder has its own _navbar.md): Docsify-This then leaves no room for one, as on
        // the Guides page (a comment here would count as content, and leave a gap above the page on phones)
        file_put_contents($out . '/_navbar.md', "\n");
    }

    // A course's card: its header image (already copied into the course's images/), name and description
    private static function courseCard(Site $course, string $banner): array
    {
        $slug = Helpers::slugFromFolder($course->courseFolder);
        $title = $course->coursePage->title();
        $image = '';
        if ($banner !== '') {
            $image = $slug . '/' . $banner;
        }
        return Helpers::card($slug . '/home', $title, $image, $title, [$course->coursePage->setting('description')]);
    }

    // Grav Markdown → Docsify Markdown, as for guides: embeds, callouts, links between the course's pages, then links
    // that only work on the Grav site
    private function convert(string $markdown, string $gravPath, string $where): string
    {
        $codeParts = [];
        $markdown = Helpers::hideCode($markdown, $codeParts);
        $markdown = Embeds::hideInjectLines($markdown, $codeParts, $this->siteUrl !== '');
        $markdown = Embeds::toDocsify($markdown);
        $markdown = Callouts::toDocsify($markdown);
        $markdown = Links::newTabToDocsify($markdown);
        $markdown = Links::mapToDocsify($markdown, $this->addresses, $gravPath, $this->pageLinks);
        foreach (Callouts::unknownShortcodes($markdown) as $name) {
            $this->warnings[] = $where . ': the [' . $name . '] shortcode only works in Grav (left as it is)';
        }
        $markdown = Links::gravOnly($markdown, $gravPath, $this->siteUrl, $where, $this->warnings, $this->pageLinks);
        return Helpers::showCode($markdown, $codeParts);
    }

    private function writeFile(string $path, string $text): void
    {
        if ($this->top !== '') {
            $text = $this->withTop($text);
        }
        $target = $this->out . '/' . $path;
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0700, true);
        }
        file_put_contents($target, rtrim($text) . "\n");
    }
}
