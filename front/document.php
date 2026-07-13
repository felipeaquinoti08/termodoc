<?php

use GlpiPlugin\Termodocs\Document;
use GlpiPlugin\Termodocs\DocumentTemplate;
use GlpiPlugin\Termodocs\Menu;

Session::checkRight(Document::$rightname, READ);

Html::header(Document::getTypeName(Session::getPluralNumber()), '', 'management', Menu::class);

// Search::show()'s pagination/sort/trash-toggle links all target this
// same bare page (Document::getSearchURL()), not the Menu hub's tabbed
// view - so unlike the normal tab-click path, landing here (e.g. via the
// trash icon) has no tab bar at all unless one is rendered by hand here,
// matching Menu::defineTabs()'s two entries.
echo '<ul class="nav nav-tabs mx-3 mt-2">';
echo '<li class="nav-item"><a class="nav-link active" href="' . Document::getSearchURL() . '">' .
    Document::getTypeName(2) . '</a></li>';
echo '<li class="nav-item"><a class="nav-link" href="' . DocumentTemplate::getSearchURL() . '">' .
    DocumentTemplate::getTypeName(2) . '</a></li>';
echo '</ul>';

Search::show(Document::class);

Html::footer();
