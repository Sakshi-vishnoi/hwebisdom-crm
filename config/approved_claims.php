<?php
/**
 * Approved Webisdom Claims & Facts
 * 
 * Strict configuration: These are the ONLY facts, clients, and service lines 
 * that the AI Agent may state in generated outreach drafts.
 * Any unapproved claims, stats, numbers, or unlisted clients will be rejected by validation.
 */

return [
    'company_name' => 'Webisdom',
    'website' => 'www.webisdom.com',
    'leadership' => 'led by IIM alumni',
    'tenure' => '12+ years (over a decade of deep expertise)',
    'primary_vertical' => 'BFSI (Banking, Financial Services, Insurance)',
    
    // The ONLY approved clients that can be named:
    'approved_clients' => [
        'HDFC',
        'KPMG',
        'PolicyBazaar',
    ],

    // The ONLY 5 approved service lines:
    'approved_service_lines' => [
        'AI Automation & Intelligent Workflows' => 'automating KYC, claims processing, customer service pipelines using AI-driven models',
        'Data Engineering & Analytics' => 'building unified data lakes and real-time dashboards that turn raw data into revenue decisions',
        'CRM Development & Implementation' => 'custom CRM solutions that give sales and relationship teams a 360-degree customer view',
        'Performance & Digital Marketing' => 'SEO 3.0, paid campaigns, and content strategies engineered specifically for high-intent audiences',
        'Web & App Development' => 'modern, compliant, high-conversion digital properties, customer portals, and mobile banking/enterprise apps',
    ],

    // Forbidden content markers for validator:
    'forbidden_terms' => [
        'guaranteed ROI',
        '100%',
        '#1 agency',
        'best in India',
        'cheapest',
    ]
];
