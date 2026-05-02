<?php

return [
    'page' => [
        'title' => 'Help',
        'heading' => 'Help Center',
        'navigation_label' => 'Help',
        'navigation_group' => 'Help and Guide',
        'badge' => 'Official User Guide',
        'card_title' => 'Exam Hall Distribution System User Guide',
        'description' => 'Download the PDF user guide to learn how to prepare data, generate exam schedules, distribute students and invigilators, and export reports.',
        'download_button' => 'Download User Guide PDF',
        'highlights' => [
            [
                'icon' => 'heroicon-o-document-text',
                'title' => 'Clear guide',
                'description' => 'Short practical sections designed for daily users.',
            ],
            [
                'icon' => 'heroicon-o-academic-cap',
                'title' => 'Admin friendly',
                'description' => 'Covers core data, rosters, exams, halls, and invigilators.',
            ],
            [
                'icon' => 'heroicon-o-arrow-down-tray',
                'title' => 'Downloadable',
                'description' => 'Generated as a PDF directly from the system.',
            ],
        ],
    ],
    'guide' => [
        'title' => 'Exam Hall Distribution System User Guide',
        'subtitle' => 'A practical guide for preparing data, creating schedules, distributing students and invigilators, and reviewing reports.',
        'cover_badge' => 'User version',
        'cover_points' => ['Core data', 'Exam schedule', 'Distribution', 'Reports'],
        'cover_flow' => ['Prepare core data.', 'Review student rosters.', 'Generate and approve schedules.', 'Export final reports.'],
        'toc_title' => 'Contents',
        'footer_page' => 'Page',
        'sections' => [
            [
                'title' => 'System overview',
                'blocks' => [
                    ['type' => 'paragraph', 'text' => 'This system helps organize exam schedules, student hall distribution, invigilator assignments, inquiries, and reports.'],
                    ['type' => 'list', 'items' => ['Manage colleges, departments, subjects, and halls.', 'Prepare subject student rosters.', 'Generate exam schedule drafts.', 'Distribute students and invigilators.', 'Export reports and review issues.']],
                ],
            ],
        ],
    ],
];
