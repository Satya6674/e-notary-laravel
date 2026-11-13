<?php

/*
| Versions of the Terms of Use and the Privacy Policy. Change a version whenever the text of
| that page changes in substance: everyone is then asked to accept the new text, and each
| acceptance is kept as its own entry in the audit trail (action "privacy.consent_given").
|
| Retention periods are what this demonstration does today. They are not a legal statement.
*/

return [
    'terms_version'   => '2026-11-04',
    'privacy_version' => '2026-11-04',

    'retention' => [
        'Audit trail'                           => 'Kept. Entries are never edited or removed by the application.',
        'One-time codes and password reset links' => 'Removed by the daily clean-up one day after they were made.',
        'Unfinished service drafts'             => 'Removed by the daily clean-up after 14 days without changes, with their uploaded files.',
        'Failed email jobs'                     => 'Removed by the daily clean-up after 30 days.',
        'Deleted accounts'                      => 'Archived, not erased: kept so the person can recover the account and the history stays complete.',
        'Uploaded documents of decided applications' => 'Kept. A document an administrator removes is kept for 365 days; deleting it after that needs an administrator to run enotary:purge-expired --apply by hand.',
    ],
];
