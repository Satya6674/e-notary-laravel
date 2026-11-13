<?php

/*
| The default service catalogue. App\Services\ServiceCatalogue is the only reader:
| it merges in what an administrator changed (stored in the settings table), and
| every page takes its services from there.
|
| kind "application": submitted through the citizen service workflow.
| kind "feature":     an existing part of the platform, reached at "destination".
|
| Document lists describe what this platform asks for. None has been confirmed by
| a legal professional, so all of them are shown as subject to confirmation until
| "requirements_verified" is turned on for that service.
*/

return [
    'statuses' => [
        'available'   => ['label' => 'Available', 'open' => true],
        'limited'     => ['label' => 'Limited', 'open' => true],
        'coming_soon' => ['label' => 'Coming Soon', 'open' => false],
        'unavailable' => ['label' => 'Unavailable', 'open' => false],
    ],

    'categories' => [
        'affidavits' => ['name' => 'Affidavit Services', 'icon' => 'fa-file-signature'],
        'agreements' => ['name' => 'Agreements & Documents', 'icon' => 'fa-file-contract'],
        'special'    => ['name' => 'Special Purpose Documents', 'icon' => 'fa-passport'],
        'digital'    => ['name' => 'Digital Services', 'icon' => 'fa-laptop-file'],
        'assistance' => ['name' => 'Notary Assistance', 'icon' => 'fa-user-tie'],
    ],

    // What the existing upload rules allow. An administrator can narrow these, not widen them.
    'formats' => ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'],
    'max_kb'  => 10240,

    'field_types' => ['text', 'textarea', 'date'],

    'services' => [
        // Affidavit Services
        [
            'slug' => 'general-affidavit', 'name' => 'General Affidavit', 'category' => 'affidavits', 'icon' => 'fa-file-lines',
            'summary' => 'Submit a draft affidavit for review by an assigned notary.',
            'description' => 'Upload your draft affidavit, state its purpose and follow the review from submission to decision.',
            'status' => 'limited', 'kind' => 'application', 'document_type' => 'affidavit', 'featured' => true,
            'instructions' => 'Use sample documents only. Check that the draft is complete and easy to read before uploading.',
            'fields' => [
                ['key' => 'deponent_name', 'label' => 'Name of the person making the statement', 'type' => 'text', 'required' => true, 'max' => 120],
                ['key' => 'statement_subject', 'label' => 'Subject of the statement', 'type' => 'text', 'required' => true, 'max' => 200],
            ],
            'documents' => [
                ['key' => 'draft_affidavit', 'name' => 'Draft affidavit', 'required' => true],
                ['key' => 'supporting_document', 'name' => 'Supporting document', 'required' => false],
            ],
        ],
        [
            'slug' => 'name-change-affidavit', 'name' => 'Name Change Affidavit', 'category' => 'affidavits', 'icon' => 'fa-signature',
            'summary' => 'Submit a draft name change affidavit with its details.',
            'description' => 'Enter the current and proposed names, upload the draft and any document that supports the change.',
            'status' => 'limited', 'kind' => 'application', 'document_type' => 'affidavit', 'featured' => true,
            'instructions' => 'Use sample documents only. Make sure both names match the draft exactly.',
            'fields' => [
                ['key' => 'current_name', 'label' => 'Current name', 'type' => 'text', 'required' => true, 'max' => 120],
                ['key' => 'proposed_name', 'label' => 'Proposed name', 'type' => 'text', 'required' => true, 'max' => 120],
                ['key' => 'change_reason', 'label' => 'Reason for the change', 'type' => 'textarea', 'required' => true, 'max' => 500],
            ],
            'documents' => [
                ['key' => 'draft_affidavit', 'name' => 'Draft name change affidavit', 'required' => true],
                ['key' => 'current_name_proof', 'name' => 'Document showing the current name', 'required' => true],
                ['key' => 'change_evidence', 'name' => 'Document supporting the change', 'required' => false],
            ],
        ],
        [
            'slug' => 'address-proof-affidavit', 'name' => 'Address Proof Affidavit', 'category' => 'affidavits', 'icon' => 'fa-house-circle-check',
            'summary' => 'Submit a draft address affidavit with the declared address.',
            'description' => 'Enter the applicant and the declared address, then upload the draft and a document showing that address.',
            'status' => 'limited', 'kind' => 'application', 'document_type' => 'affidavit', 'featured' => true,
            'instructions' => 'Use sample documents only. Enter the address exactly as it appears in the draft.',
            'fields' => [
                ['key' => 'applicant_name', 'label' => 'Applicant name', 'type' => 'text', 'required' => true, 'max' => 120],
                ['key' => 'declared_address', 'label' => 'Declared address', 'type' => 'textarea', 'required' => true, 'max' => 500],
            ],
            'documents' => [
                ['key' => 'draft_affidavit', 'name' => 'Draft address affidavit', 'required' => true],
                ['key' => 'address_document', 'name' => 'Document showing the address', 'required' => true],
            ],
        ],
        ['slug' => 'one-and-same-person-affidavit', 'name' => 'One and Same Person Affidavit', 'category' => 'affidavits', 'icon' => 'fa-people-arrows', 'summary' => 'For records that show one person under different names.', 'status' => 'coming_soon', 'kind' => 'application', 'document_type' => 'affidavit'],
        ['slug' => 'date-of-birth-affidavit', 'name' => 'Date of Birth Affidavit', 'category' => 'affidavits', 'icon' => 'fa-cake-candles', 'summary' => 'For a statement about a date of birth.', 'status' => 'coming_soon', 'kind' => 'application', 'document_type' => 'affidavit'],
        ['slug' => 'income-affidavit', 'name' => 'Income Affidavit', 'category' => 'affidavits', 'icon' => 'fa-indian-rupee-sign', 'summary' => 'For a statement about income.', 'status' => 'coming_soon', 'kind' => 'application', 'document_type' => 'affidavit'],
        ['slug' => 'gap-year-affidavit', 'name' => 'Gap Year Affidavit', 'category' => 'affidavits', 'icon' => 'fa-calendar-minus', 'summary' => 'For a statement about a break in studies.', 'status' => 'coming_soon', 'kind' => 'application', 'document_type' => 'affidavit'],
        ['slug' => 'marriage-related-affidavit', 'name' => 'Marriage-related Affidavit', 'category' => 'affidavits', 'icon' => 'fa-ring', 'summary' => 'For a statement relating to a marriage.', 'status' => 'coming_soon', 'kind' => 'application', 'document_type' => 'affidavit'],

        // Agreements & Documents
        [
            'slug' => 'rent-agreement', 'name' => 'Rent Agreement', 'category' => 'agreements', 'icon' => 'fa-house-chimney-user',
            'summary' => 'Submit a draft rent agreement with landlord, tenant and property details.',
            'description' => 'Enter the parties and the property, then upload the draft agreement for review.',
            'status' => 'limited', 'kind' => 'application', 'document_type' => 'rent_agreement', 'featured' => true,
            'instructions' => 'Use sample documents only. Check that names and the property address match the draft.',
            'fields' => [
                ['key' => 'landlord_name', 'label' => 'Landlord name', 'type' => 'text', 'required' => true, 'max' => 120],
                ['key' => 'tenant_name', 'label' => 'Tenant name', 'type' => 'text', 'required' => true, 'max' => 120],
                ['key' => 'property_address', 'label' => 'Property address', 'type' => 'textarea', 'required' => true, 'max' => 500],
                ['key' => 'start_date', 'label' => 'Agreement start date', 'type' => 'date', 'required' => false, 'max' => 10],
            ],
            'documents' => [
                ['key' => 'draft_agreement', 'name' => 'Draft rent agreement', 'required' => true],
                ['key' => 'property_document', 'name' => 'Document showing the property', 'required' => false],
            ],
        ],
        ['slug' => 'lease-agreement', 'name' => 'Lease Agreement', 'category' => 'agreements', 'icon' => 'fa-building', 'summary' => 'For a longer-term lease of a property.', 'status' => 'coming_soon', 'kind' => 'application', 'document_type' => 'other'],
        [
            'slug' => 'indemnity-bond', 'name' => 'Indemnity Bond', 'category' => 'agreements', 'icon' => 'fa-shield-halved',
            'summary' => 'Submit a draft indemnity bond for review.',
            'description' => 'Enter the parties and upload the draft bond for review by an assigned notary.',
            'status' => 'limited', 'kind' => 'application', 'document_type' => 'indemnity_bond', 'featured' => true,
            'instructions' => 'Use sample documents only. Check that the parties match the draft.',
            'fields' => [
                ['key' => 'indemnifier_name', 'label' => 'Name of the person giving the indemnity', 'type' => 'text', 'required' => true, 'max' => 120],
                ['key' => 'beneficiary_name', 'label' => 'Name of the person protected', 'type' => 'text', 'required' => true, 'max' => 120],
            ],
            'documents' => [
                ['key' => 'draft_bond', 'name' => 'Draft indemnity bond', 'required' => true],
            ],
        ],
        ['slug' => 'consent-form', 'name' => 'Consent Form', 'category' => 'agreements', 'icon' => 'fa-file-circle-check', 'summary' => 'For a written consent.', 'status' => 'coming_soon', 'kind' => 'application', 'document_type' => 'other'],
        [
            'slug' => 'undertaking', 'name' => 'Undertaking', 'category' => 'agreements', 'icon' => 'fa-handshake',
            'summary' => 'Submit a draft undertaking for review.',
            'description' => 'State what is being undertaken and upload the draft for review by an assigned notary.',
            'status' => 'limited', 'kind' => 'application', 'document_type' => 'undertaking',
            'instructions' => 'Use sample documents only.',
            'fields' => [
                ['key' => 'given_by', 'label' => 'Name of the person giving the undertaking', 'type' => 'text', 'required' => true, 'max' => 120],
                ['key' => 'given_to', 'label' => 'Given to', 'type' => 'text', 'required' => true, 'max' => 120],
            ],
            'documents' => [
                ['key' => 'draft_undertaking', 'name' => 'Draft undertaking', 'required' => true],
            ],
        ],
        [
            'slug' => 'declaration', 'name' => 'Declaration', 'category' => 'agreements', 'icon' => 'fa-file-pen',
            'summary' => 'Submit a draft declaration for review.',
            'description' => 'State who is making the declaration and upload the draft for review by an assigned notary.',
            'status' => 'limited', 'kind' => 'application', 'document_type' => 'declaration',
            'instructions' => 'Use sample documents only.',
            'fields' => [
                ['key' => 'declarant_name', 'label' => 'Name of the person making the declaration', 'type' => 'text', 'required' => true, 'max' => 120],
            ],
            'documents' => [
                ['key' => 'draft_declaration', 'name' => 'Draft declaration', 'required' => true],
                ['key' => 'supporting_document', 'name' => 'Supporting document', 'required' => false],
            ],
        ],
        [
            'slug' => 'power-of-attorney', 'name' => 'Power of Attorney', 'category' => 'agreements', 'icon' => 'fa-user-shield',
            'summary' => 'Submit a draft power of attorney with its parties and scope.',
            'description' => 'Enter the principal, the agent and the scope of authority, then upload the draft for review.',
            'status' => 'limited', 'kind' => 'application', 'document_type' => 'power_of_attorney', 'featured' => true,
            'instructions' => 'Use sample documents only. Describe the authority as it appears in the draft.',
            'fields' => [
                ['key' => 'principal_name', 'label' => 'Principal name', 'type' => 'text', 'required' => true, 'max' => 120],
                ['key' => 'agent_name', 'label' => 'Agent name', 'type' => 'text', 'required' => true, 'max' => 120],
                ['key' => 'authority_scope', 'label' => 'Scope of authority', 'type' => 'textarea', 'required' => true, 'max' => 500],
            ],
            'documents' => [
                ['key' => 'draft_power_of_attorney', 'name' => 'Draft power of attorney', 'required' => true],
                ['key' => 'supporting_document', 'name' => 'Supporting document', 'required' => false],
            ],
        ],

        // Special Purpose Documents
        ['slug' => 'nri-affidavit', 'name' => 'NRI Affidavit', 'category' => 'special', 'icon' => 'fa-earth-asia', 'summary' => 'For a statement by a non-resident Indian.', 'status' => 'coming_soon', 'kind' => 'application', 'document_type' => 'affidavit'],
        ['slug' => 'passport-related-affidavit', 'name' => 'Passport-related Affidavit', 'category' => 'special', 'icon' => 'fa-passport', 'summary' => 'For a statement relating to a passport.', 'status' => 'coming_soon', 'kind' => 'application', 'document_type' => 'affidavit'],
        ['slug' => 'education-related-affidavit', 'name' => 'Education-related Affidavit', 'category' => 'special', 'icon' => 'fa-graduation-cap', 'summary' => 'For a statement relating to education.', 'status' => 'coming_soon', 'kind' => 'application', 'document_type' => 'affidavit'],
        ['slug' => 'lost-document-declaration', 'name' => 'Lost Document Declaration', 'category' => 'special', 'icon' => 'fa-magnifying-glass', 'summary' => 'For a declaration about a lost document.', 'status' => 'coming_soon', 'kind' => 'application', 'document_type' => 'declaration'],

        // Digital Services
        ['slug' => 'document-drafting', 'name' => 'Document Drafting', 'category' => 'digital', 'icon' => 'fa-pen-nib', 'summary' => 'Help preparing a document. Not offered yet.', 'status' => 'coming_soon', 'kind' => 'feature'],
        ['slug' => 'secure-document-upload', 'name' => 'Secure Document Upload', 'category' => 'digital', 'icon' => 'fa-cloud-arrow-up', 'summary' => 'Upload a document privately to start a general application.', 'description' => 'Files are kept outside the public web folder. Only you, your assigned notary and administrators can open them. Uploads are checked for type and size, but not scanned for malware.', 'status' => 'available', 'kind' => 'feature', 'destination' => 'user.upload'],
        ['slug' => 'pdf-preview', 'name' => 'PDF Preview', 'category' => 'digital', 'icon' => 'fa-file-pdf', 'summary' => 'View PDF and image files in the browser.', 'description' => 'PDF, JPG and PNG files can be previewed. Word files can be downloaded, not previewed.', 'status' => 'available', 'kind' => 'feature', 'destination' => 'user.documents'],
        ['slug' => 'application-tracking', 'name' => 'Application Tracking', 'category' => 'digital', 'icon' => 'fa-route', 'summary' => 'See the status and history of each application.', 'description' => 'Every application shows its current status and a dated history of each step.', 'status' => 'available', 'kind' => 'feature', 'destination' => 'user.requests'],
        ['slug' => 'appointment-request', 'name' => 'Appointment Request', 'category' => 'digital', 'icon' => 'fa-calendar-check', 'summary' => 'Request a session for one of your applications.', 'description' => 'Request a video or in-person session and follow its confirmation in the portal. The platform does not provide a video-call link.', 'status' => 'available', 'kind' => 'feature', 'destination' => 'appointments.index'],

        // Notary Assistance
        ['slug' => 'notary-appointment', 'name' => 'Notary Appointment', 'category' => 'assistance', 'icon' => 'fa-user-clock', 'summary' => 'Arrange a session with the notary assigned to your application.', 'description' => 'Once a notary is assigned to your application, you can request a session and follow its confirmation.', 'status' => 'available', 'kind' => 'feature', 'destination' => 'appointments.index'],
        ['slug' => 'application-review', 'name' => 'Application Review', 'category' => 'assistance', 'icon' => 'fa-magnifying-glass-chart', 'summary' => 'An assigned notary reviews your application and records a decision.', 'description' => 'The notary can approve, reject or request a correction, and must record a reason. The outcome has no legal effect.', 'status' => 'available', 'kind' => 'feature', 'destination' => 'user.requests'],
        ['slug' => 'document-correction', 'name' => 'Document Correction', 'category' => 'assistance', 'icon' => 'fa-file-pen', 'summary' => 'Respond when a notary asks for a correction.', 'description' => 'If a correction is requested, you can see the reason and send an updated file from the application page.', 'status' => 'available', 'kind' => 'feature', 'destination' => 'user.requests'],
        ['slug' => 'support', 'name' => 'Support', 'category' => 'assistance', 'icon' => 'fa-life-ring', 'summary' => 'Open a support ticket from your portal.', 'description' => 'Raise a ticket from your portal and follow the replies there.', 'status' => 'available', 'kind' => 'feature', 'destination' => 'support.index'],
    ],
];
