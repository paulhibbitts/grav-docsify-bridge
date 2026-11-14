<?php
namespace OPG;

// The guides of an Open Publishing Space site (an optional Guides page, then each guide), or an Open Course Space
// course (its settings, its Modules page with a page per module, its menu pages and its sidebar)
class Site
{
    public ?Page $guidesPage = null;
    public array $guides = [];
    public ?Page $modulesPage = null;
    public array $coursePages = [];
    public ?Page $courseSidebar = null;
    public ?Page $courseHome = null;     // the course's home page; its children are the parts it shows (page-inject)
    public ?Page $courseFooter = null;
    public string $name = '';            // the site's name, when the Docsify site shows one ('' if not known)
    public string $courseFolder = '';    // the course's own folder, e.g. '01.cpt363-basic'
    public ?Page $coursePage = null;     // the course's settings (course.md): its title, description, Course Color …
    public ?Page $headerImage = null;    // the banner on the course's home page (headerimage/ and its image)
}
