<?php
// profile_fields.php — Define mandatory profile fields
return [
    'full_name' => [
        'label' => 'Full Name',
        'type' => 'text',
        'required' => true,
        'placeholder' => 'Enter your full name'
    ],
    'phone' => [
        'label' => 'Phone Number',
        'type' => 'tel',
        'required' => true,
        'placeholder' => '+91 XXXXX XXXXX'
    ],
    'country' => [
        'label' => 'Country',
        'type' => 'select',
        'required' => true,
        'options' => [
            '' => 'Select Country',
            'IN' => 'India',
            'US' => 'United States',
            'UK' => 'United Kingdom',
            'CA' => 'Canada',
            'AU' => 'Australia',
            'DE' => 'Germany',
            'FR' => 'France',
            'JP' => 'Japan',
            'CN' => 'China',
            'BR' => 'Brazil',
            'ZA' => 'South Africa',
            'Other' => 'Other'
        ]
    ],
    'trading_experience' => [
        'label' => 'Trading Experience (Years)',
        'type' => 'number',
        'required' => true,
        'min' => 0,
        'max' => 50,
        'placeholder' => 'How many years?'
    ],
    'platform_used' => [
        'label' => 'Trading Platform Used',
        'type' => 'text',
        'required' => true,
        'placeholder' => 'e.g., Zerodha, Upstox, MetaTrader, etc.'
    ],
    'why_join' => [
        'label' => 'Why do you want to join?',
        'type' => 'textarea',
        'required' => true,
        'placeholder' => 'Tell us why you want to join Shaikhoology Trading League'
    ]
];