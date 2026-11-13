<?php

/*
| Footer content. To add, remove, relabel, hide or reorder a link, change it here:
| the footer view has no links of its own. App\Support\SiteLinks drops anything that
| is hidden or has no working destination.
|
| A link is one of:
|   ['label' => ..., 'route' => 'route.name', 'fragment' => 'section']
|   ['label' => ..., 'url' => 'https://...']
|   ['label' => ..., 'service' => 'service-slug']   name and status come from the catalogue
|
| Leave a link out, or set 'visible' => false, until its page genuinely exists.
| A column's 'categories' show a catalogue category with a line of text, and are
| left out while that category has no open services.
*/

return [
    // Only details the owner has confirmed. Email comes from the admin's Support Email setting.
    'phone'  => env('SITE_PHONE'),
    'social' => [
        // ['label' => 'LinkedIn', 'icon' => 'fa-link', 'url' => 'https://...'],
    ],

    'columns' => [
        [
            'title' => 'Product',
            'links' => [
                ['label' => 'Platform Features', 'route' => 'home', 'fragment' => 'features'],
                ['label' => 'How It Works', 'route' => 'home', 'fragment' => 'how-it-works'],
                ['label' => 'Solutions', 'route' => 'solutions', 'visible' => false],
                ['label' => 'Pricing & Plans', 'route' => 'pricing', 'visible' => false],
                ['label' => 'Verify a Certificate', 'route' => 'verify.index'],
                ['label' => 'FAQs', 'route' => 'home', 'fragment' => 'faq'],
            ],
        ],
        [
            'title' => 'Our Services',
            'wide'  => true,
            // Keys from config/service_catalogue.php, which supplies each name and icon.
            'categories' => [
                'affidavits' => 'General, Name Change and Address Proof.',
                'agreements' => 'Rent Agreement, Indemnity Bond and Power of Attorney.',
                'digital'    => 'Secure Document Upload and Application Tracking.',
                'assistance' => 'Appointments and Application Review.',
            ],
            'cta' => ['label' => 'Explore All Services', 'route' => 'services.index'],
        ],
        [
            'title' => 'Legal & Policies',
            'links' => [
                ['label' => 'Privacy Policy', 'route' => 'privacy'],
                ['label' => 'Terms of Service', 'route' => 'terms'],
                ['label' => 'User Terms', 'route' => 'user-terms', 'visible' => false],
                ['label' => 'Notary Terms', 'route' => 'notary-terms', 'visible' => false],
                ['label' => 'Refund Policy', 'route' => 'refund-policy', 'visible' => false],
                ['label' => 'Disclaimer', 'route' => 'disclaimer'],
            ],
        ],
        [
            'title' => 'Company',
            'links' => [
                ['label' => 'About E-Notary', 'route' => 'about'],
                ['label' => 'Contact Us', 'route' => 'home', 'fragment' => 'contact'],
                ['label' => 'Help & Support', 'route' => 'support.index'],
                ['label' => 'Careers', 'route' => 'careers', 'visible' => false],
            ],
        ],
    ],
];
