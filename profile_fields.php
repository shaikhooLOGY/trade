<?php
// profile_fields.php — Enhanced comprehensive profile fields for completion
return [
    // Section 1: Personal Information
    'personal_info' => [
        'title' => 'Personal Information',
        'description' => 'Basic personal details',
        'fields' => [
            'full_name' => [
                'label' => 'Full Name',
                'type' => 'text',
                'required' => true,
                'placeholder' => 'Enter your full legal name',
                'validation' => 'min:2|max:100'
            ],
            'age' => [
                'label' => 'Age',
                'type' => 'number',
                'required' => true,
                'min' => 18,
                'max' => 100,
                'placeholder' => 'Your current age'
            ],
            'location' => [
                'label' => 'Current Location',
                'type' => 'text',
                'required' => true,
                'placeholder' => 'City, State, Country',
                'validation' => 'min:3|max:200'
            ],
            'phone' => [
                'label' => 'Phone Number',
                'type' => 'tel',
                'required' => true,
                'placeholder' => '+91 XXXXX XXXXX',
                'validation' => 'regex:/^\+?[\d\s\-\(\)]+$/'
            ]
        ]
    ],
    
    // Section 2: Educational Background
    'education' => [
        'title' => 'Educational Background',
        'description' => 'Your educational qualifications',
        'fields' => [
            'education_level' => [
                'label' => 'Highest Education Level',
                'type' => 'select',
                'required' => true,
                'options' => [
                    '' => 'Select Education Level',
                    'high_school' => 'High School',
                    'bachelor' => 'Bachelor\'s Degree',
                    'master' => 'Master\'s Degree',
                    'phd' => 'PhD',
                    'other' => 'Other'
                ]
            ],
            'institution' => [
                'label' => 'Institution/University',
                'type' => 'text',
                'required' => false,
                'placeholder' => 'Name of your educational institution'
            ],
            'graduation_year' => [
                'label' => 'Graduation Year',
                'type' => 'number',
                'required' => false,
                'min' => 1950,
                'max' => date('Y') + 5,
                'placeholder' => 'Year of graduation'
            ]
        ]
    ],
    
    // Section 3: Trading Experience
    'trading_experience' => [
        'title' => 'Trading Experience',
        'description' => 'Your trading background and experience',
        'fields' => [
            'trading_experience_years' => [
                'label' => 'Years of Trading Experience',
                'type' => 'number',
                'required' => true,
                'min' => 0,
                'max' => 50,
                'placeholder' => 'How many years have you been trading?'
            ],
            'trading_markets' => [
                'label' => 'Markets You Trade',
                'type' => 'checkbox_group',
                'required' => true,
                'options' => [
                    'stocks' => 'Stocks/Equities',
                    'forex' => 'Forex',
                    'crypto' => 'Cryptocurrency',
                    'commodities' => 'Commodities',
                    'options' => 'Options',
                    'futures' => 'Futures',
                    'bonds' => 'Bonds',
                    'indices' => 'Indices'
                ],
                'min_selections' => 1
            ],
            'trading_strategies' => [
                'label' => 'Trading Strategies Used',
                'type' => 'checkbox_group',
                'required' => true,
                'options' => [
                    'day_trading' => 'Day Trading',
                    'swing_trading' => 'Swing Trading',
                    'position_trading' => 'Position Trading',
                    'scalping' => 'Scalping',
                    'technical_analysis' => 'Technical Analysis',
                    'fundamental_analysis' => 'Fundamental Analysis',
                    'algorithmic' => 'Algorithmic Trading',
                    'value_investing' => 'Value Investing',
                    'growth_investing' => 'Growth Investing',
                    'momentum_trading' => 'Momentum Trading'
                ],
                'min_selections' => 1
            ],
            'platform_used' => [
                'label' => 'Primary Trading Platform',
                'type' => 'text',
                'required' => true,
                'placeholder' => 'e.g., Zerodha, Upstox, MetaTrader, E*TRADE',
                'validation' => 'min:2|max:100'
            ],
            'previous_trading_results' => [
                'label' => 'Previous Trading Results',
                'type' => 'textarea',
                'required' => false,
                'placeholder' => 'Describe your previous trading results, profits/losses, or notable achievements'
            ]
        ]
    ],
    
    // Section 4: Investment Goals and Risk Tolerance
    'investment_goals' => [
        'title' => 'Investment Goals & Risk Tolerance',
        'description' => 'Your financial objectives and risk preferences',
        'fields' => [
            'investment_goals' => [
                'label' => 'Investment Goals',
                'type' => 'textarea',
                'required' => true,
                'placeholder' => 'What are your primary investment goals? (e.g., retirement planning, wealth building, passive income)'
            ],
            'risk_tolerance' => [
                'label' => 'Risk Tolerance',
                'type' => 'radio',
                'required' => true,
                'options' => [
                    'conservative' => 'Conservative - Low risk, stable returns',
                    'moderate' => 'Moderate - Balanced risk and returns',
                    'aggressive' => 'Aggressive - High risk, high potential returns',
                    'very_aggressive' => 'Very Aggressive - Maximum risk for maximum returns'
                ]
            ],
            'investment_timeframe' => [
                'label' => 'Investment Timeframe',
                'type' => 'select',
                'required' => true,
                'options' => [
                    '' => 'Select Timeframe',
                    'short_term' => 'Short-term (1-6 months)',
                    'medium_term' => 'Medium-term (6 months - 2 years)',
                    'long_term' => 'Long-term (2+ years)'
                ]
            ]
        ]
    ],
    
    // Section 5: Trading Capital and Financial Information
    'financial_info' => [
        'title' => 'Financial Information',
        'description' => 'Your financial capacity and capital',
        'fields' => [
            'trading_capital' => [
                'label' => 'Available Trading Capital',
                'type' => 'number',
                'required' => true,
                'min' => 1000,
                'max' => 10000000,
                'step' => 1000,
                'placeholder' => 'Amount you can allocate for trading',
                'help_text' => 'This should be capital you can afford to risk'
            ],
            'monthly_income' => [
                'label' => 'Monthly Income',
                'type' => 'number',
                'required' => true,
                'min' => 0,
                'max' => 1000000,
                'step' => 1000,
                'placeholder' => 'Your approximate monthly income'
            ],
            'net_worth' => [
                'label' => 'Net Worth (Approximate)',
                'type' => 'number',
                'required' => false,
                'min' => 0,
                'max' => 100000000,
                'step' => 10000,
                'placeholder' => 'Approximate total net worth'
            ],
            'trading_budget_percentage' => [
                'label' => 'Percentage of Income for Trading',
                'type' => 'number',
                'required' => true,
                'min' => 1,
                'max' => 50,
                'step' => 0.1,
                'placeholder' => 'What % of your income do you want to allocate to trading?',
                'help_text' => 'Recommended: 5-15% of income'
            ]
        ]
    ],
    
    // Section 6: Trading Psychology Assessment
    'psychology_assessment' => [
        'title' => 'Trading Psychology Assessment',
        'description' => 'Understanding your trading mindset and discipline',
        'fields' => [
            'emotional_control_rating' => [
                'label' => 'Emotional Control (1-10)',
                'type' => 'range',
                'required' => true,
                'min' => 1,
                'max' => 10,
                'step' => 1,
                'help_text' => 'Rate your ability to control emotions while trading (1=Poor, 10=Excellent)'
            ],
            'discipline_rating' => [
                'label' => 'Discipline Level (1-10)',
                'type' => 'range',
                'required' => true,
                'min' => 1,
                'max' => 10,
                'step' => 1,
                'help_text' => 'Rate your discipline in following trading rules (1=Poor, 10=Excellent)'
            ],
            'patience_rating' => [
                'label' => 'Patience Level (1-10)',
                'type' => 'range',
                'required' => true,
                'min' => 1,
                'max' => 10,
                'step' => 1,
                'help_text' => 'Rate your patience in waiting for right opportunities (1=Poor, 10=Excellent)'
            ],
            'trading_psychology_questions' => [
                'label' => 'Trading Psychology Questions',
                'type' => 'checkbox_group',
                'required' => true,
                'options' => [
                    'handle_losses' => 'I can handle significant trading losses without emotional distress',
                    'follow_plan' => 'I always follow my trading plan and risk management rules',
                    'continuous_learning' => 'I am committed to continuous learning and improvement',
                    'no_gambling' => 'I understand trading is not gambling but requires skill and strategy',
                    'patience_waits' => 'I am patient and wait for high-probability setups',
                    'emotional_control' => 'I can control emotions like fear and greed while trading'
                ],
                'min_selections' => 4,
                'help_text' => 'Select all that apply to you'
            ]
        ]
    ],
    
    // Section 7: Why they want to join Shaikhoology
    'why_join' => [
        'title' => 'Why Do You Want to Join Shaikhoology?',
        'description' => 'Your motivation and expectations',
        'fields' => [
            'why_join' => [
                'label' => 'Why do you want to join Shaikhoology?',
                'type' => 'textarea',
                'required' => true,
                'placeholder' => 'Explain your motivation to join our trading community',
                'min_length' => 50,
                'max_length' => 1000
            ],
            'expectations' => [
                'label' => 'What are your expectations?',
                'type' => 'textarea',
                'required' => true,
                'placeholder' => 'What do you hope to achieve and learn from Shaikhoology?',
                'min_length' => 30,
                'max_length' => 800
            ],
            'commitment_level' => [
                'label' => 'Commitment Level',
                'type' => 'radio',
                'required' => true,
                'options' => [
                    'casual' => 'Casual - Learn for personal interest',
                    'serious' => 'Serious - Committed to improving trading skills',
                    'dedicated' => 'Dedicated - Want to become a professional trader',
                    'professional' => 'Professional - Already trading professionally'
                ]
            ],
            'time_availability' => [
                'label' => 'Time Availability for Trading',
                'type' => 'select',
                'required' => true,
                'options' => [
                    '' => 'Select availability',
                    'part_time' => 'Part-time (1-3 hours daily)',
                    'full_time' => 'Full-time (4+ hours daily)',
                    'weekend' => 'Weekends only',
                    'after_work' => 'After work hours',
                    'flexible' => 'Flexible schedule'
                ]
            ]
        ]
    ],
    
    // Section 8: References or recommendations
    'references' => [
        'title' => 'References (Optional)',
        'description' => 'Optional references who can vouch for you',
        'fields' => [
            'reference_name' => [
                'label' => 'Reference Name',
                'type' => 'text',
                'required' => false,
                'placeholder' => 'Full name of your reference'
            ],
            'reference_contact' => [
                'label' => 'Reference Contact',
                'type' => 'text',
                'required' => false,
                'placeholder' => 'Email or phone number of reference'
            ],
            'reference_relationship' => [
                'label' => 'Relationship to Reference',
                'type' => 'text',
                'required' => false,
                'placeholder' => 'e.g., Former colleague, Mentor, Friend'
            ],
            'reference_details' => [
                'label' => 'Additional Reference Details',
                'type' => 'textarea',
                'required' => false,
                'placeholder' => 'Any additional information about your reference'
            ]
        ]
    ]
];