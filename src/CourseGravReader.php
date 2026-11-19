<?php
namespace OPG;

// Reads an Open Course Space course from its own folder: its settings (course.md), the Modules page (module.md) with
// a page per module – or, in a course without one, its Weekly Units (a blog page, blog.md, with a page per week, used
// as the modules) – the home page and the parts it shows, the menu pages (Schedule, Resources …), the header image,
// the course sidebar and the footer. Other pages, such as a blog, aren't part of the modules, so they're listed in
// $notes.
class CourseGravReader extends GravReader
{
    public function read(string $pagesDir): Site
    {
        $site = new Site();
        $this->notes = [];
        $site->courseFolder = basename($pagesDir);
        $site->coursePage = $this->readPage($pagesDir, '', 'course.md', 'course');
        // a course without a Modules page is organised by weekly units: its first blog page
        $unitsFolder = '';
        if (count(glob($pagesDir . '/*/module.md')) === 0) {
            foreach ($this->subfolders($pagesDir) as $name) {
                if ($unitsFolder === '' && Helpers::isNumbered($name) && $this->markdownFile($pagesDir . '/' . $name) === 'blog.md') {
                    $unitsFolder = $name;
                }
            }
        }
        foreach ($this->subfolders($pagesDir) as $name) {
            $folder = $pagesDir . '/' . $name;
            $file = $this->markdownFile($folder);
            if ($file === '') {
                continue;
            }
            if ($file === 'module.md' || $name === $unitsFolder) {
                $site->modulesPage = $this->readModules($pagesDir, $name, $file);
                continue;
            }
            if ($name === 'sidebar') {
                $site->courseSidebar = $this->readPage($pagesDir, $name, $file, 'sidebar');
                continue;
            }
            if ($name === 'footer') {
                $site->courseFooter = $this->readPage($pagesDir, $name, $file, 'footer');
                continue;
            }
            if ($name === 'headerimage') {
                $site->headerImage = $this->readPage($pagesDir, $name, $file, 'headerimage');
                continue;
            }
            // the skeleton's Read Me page is about setting up the Grav site (Git Sync, the Admin panel), not the course
            if (Helpers::slugFromFolder($name) === 'readme') {
                $page = $this->readPage($pagesDir, $name, $file, 'menu');
                $this->notes[] = $page->title() . ' (' . $name . ') is about the Grav site, so it wasn\'t moved';
                continue;
            }
            if (Helpers::slugFromFolder($name) === 'home') {
                $site->courseHome = $this->readHome($pagesDir, $name, $file);
                continue;
            }
            $page = $this->readPage($pagesDir, $name, $file, 'menu');
            if ($this->isMenuPage($name, $file, $page)) {
                $site->coursePages[] = $page;
            } elseif (Helpers::isNumbered($name) && ($page->meta['routable'] ?? true) !== false) {
                $this->notes[] = $page->title() . ' (' . $name . ') isn\'t part of the modules, so it wasn\'t moved';
            }
        }
        return $site;
    }

    // The Modules page and its module pages, in Grav's order – or the Weekly Units page and its weeks (week-01 …),
    // in name order
    private function readModules(string $pagesDir, string $modulesFolder, string $listFile): Page
    {
        $modulesPage = $this->readPage($pagesDir, $modulesFolder, $listFile, 'modules');
        foreach ($this->subfolders($pagesDir . '/' . $modulesFolder) as $name) {
            $folder = $modulesFolder . '/' . $name;
            $file = $this->markdownFile($pagesDir . '/' . $folder);
            if ($file !== '' && (Helpers::isNumbered($name) || $listFile === 'blog.md')) {
                $module = $this->readPage($pagesDir, $folder, $file, 'module');
                // the pages inside a module (as the Common Cartridge Converter writes a module's items), in order
                foreach ($this->subfolders($pagesDir . '/' . $folder) as $pageName) {
                    $pageFolder = $folder . '/' . $pageName;
                    $pageFile = $this->markdownFile($pagesDir . '/' . $pageFolder);
                    if ($pageFile === '') {
                        continue;
                    }
                    $page = $this->readPage($pagesDir, $pageFolder, $pageFile, 'module-page');
                    // only numbered course pages, one level inside the module, are carried across: anything else is noted
                    if ($pageFile !== 'course-page.md' || !Helpers::isNumbered($pageName)) {
                        $this->notes[] = $page->title() . ' (' . $pageFolder . ') isn\'t a page of its module, so it wasn\'t moved';
                        continue;
                    }
                    foreach ($this->subfolders($pagesDir . '/' . $pageFolder) as $deeper) {
                        if ($this->markdownFile($pagesDir . '/' . $pageFolder . '/' . $deeper) !== '') {
                            $this->notes[] = $pageFolder . '/' . $deeper . ' is a page inside a page of a module, so it wasn\'t moved';
                        }
                    }
                    $module->children[] = $page;
                }
                $modulesPage->children[] = $module;
            }
        }
        return $modulesPage;
    }

    // The home page and the parts it shows with [plugin:page-inject](../home/_part) – an announcement, this week's
    // reminders, next week's preparations – in the order it shows them
    private function readHome(string $pagesDir, string $homeFolder, string $file): Page
    {
        $home = $this->readPage($pagesDir, $homeFolder, $file, 'home');
        foreach ($this->subfolders($pagesDir . '/' . $homeFolder) as $name) {
            $partFile = $this->markdownFile($pagesDir . '/' . $homeFolder . '/' . $name);
            if ($partFile === '') {
                continue;
            }
            // a part's folder starts with _ ('01._reminders'); any other is a page of its own that the home page
            // shows as a card ('02.syllabus', as the Common Cartridge Converter writes a course's welcome pages)
            // (a page the home page shows with [plugin:page-inject] is a part too, whatever its folder's name)
            $slug = Helpers::slugFromFolder($name);
            $injected = str_contains($home->body, '/' . $slug . ')');
            $kind = (str_starts_with($slug, '_') || $injected) ? 'home-part' : 'home-page';
            $home->children[] = $this->readPage($pagesDir, $homeFolder . '/' . $name, $partFile, $kind);
        }
        return $home;
    }

    // A numbered, visible course page (not the blog or other page types)
    private function isMenuPage(string $name, string $file, Page $page): bool
    {
        $isVisible = ($page->meta['visible'] ?? true) !== false && ($page->meta['routable'] ?? true) !== false;
        return Helpers::isNumbered($name) && $file === 'course-page.md' && $isVisible;
    }
}
