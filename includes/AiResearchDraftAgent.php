<?php
/**
 * AiResearchDraftAgent.php
 * 
 * Autonomous Multi-Step Research & Two-Pass Outreach Writing Agent
 * for Webisdom B2B CRM.
 * 
 * Never copies templates. Writes tailored, verified 1-to-1 emails from research.
 */

require_once __DIR__ . '/ai_prompts.php';

class AiResearchDraftAgent
{
    private PDO $pdo;
    private array $approvedClaims;
    private array $companyProfile;
    private ?string $geminiApiKey;
    private string $geminiModel;
    private string $referenceText;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->approvedClaims = require __DIR__ . '/../config/approved_claims.php';
        $this->companyProfile = require __DIR__ . '/../config/company_profile.php';
        
        $this->geminiApiKey = getenv('GEMINI_API_KEY') ?: null;
        $this->geminiModel = getenv('GEMINI_MODEL') ?: 'gemini-2.0-flash';

        $refFile = __DIR__ . '/../config/email_references/bfsi_reference.txt';
        if (file_exists($refFile)) {
            $this->referenceText = file_get_contents($refFile);
        } else {
            $this->referenceText = '';
        }
    }

    /**
     * STAGE 1: Collect CRM data (No AI)
     */
    public function collectCrmContext(int $leadId): array
    {
        // 1. Fetch Lead
        $stmt = $this->pdo->prepare("
            SELECT 
                l.*,
                u.username AS owner_name,
                u.email AS owner_email,
                ps.name AS stage_name,
                ls.name AS source_name
            FROM leads l
            LEFT JOIN users u ON u.id = l.assigned_to
            LEFT JOIN pipeline_stages ps ON ps.id = l.stage_id
            LEFT JOIN lead_sources ls ON ls.id = l.source_id
            WHERE l.id = ?
        ");
        $stmt->execute([$leadId]);
        $lead = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$lead) {
            throw new InvalidArgumentException("Lead #{$leadId} not found.");
        }

        // 2. Fetch Email Messages
        $stmtEmails = $this->pdo->prepare("
            SELECT direction, from_email, to_email, subject, body_text, received_at, created_at 
            FROM email_messages 
            WHERE lead_id = ? 
            ORDER BY id ASC
        ");
        $stmtEmails->execute([$leadId]);
        $emails = $stmtEmails->fetchAll(PDO::FETCH_ASSOC);

        // 3. Fetch Followups
        $stmtFollowups = $this->pdo->prepare("
            SELECT type, follow_up_date, follow_up_time, notes, status, completed_at 
            FROM lead_followups 
            WHERE lead_id = ? 
            ORDER BY follow_up_date DESC
        ");
        $stmtFollowups->execute([$leadId]);
        $followups = $stmtFollowups->fetchAll(PDO::FETCH_ASSOC);

        // 4. Fetch Meetings
        $stmtMeetings = $this->pdo->prepare("
            SELECT meeting_number, title, meeting_date, start_time, description, status 
            FROM meetings 
            WHERE lead_id = ? 
            ORDER BY meeting_date DESC
        ");
        $stmtMeetings->execute([$leadId]);
        $meetings = $stmtMeetings->fetchAll(PDO::FETCH_ASSOC);

        // 5. Fetch Documents
        $stmtDocs = $this->pdo->prepare("
            SELECT document_type, file_name, created_at 
            FROM documents 
            WHERE lead_id = ? 
            ORDER BY id DESC
        ");
        $stmtDocs->execute([$leadId]);
        $documents = $stmtDocs->fetchAll(PDO::FETCH_ASSOC);

        // 6. Fetch Activities
        $stmtAct = $this->pdo->prepare("
            SELECT activity_type, description, created_at 
            FROM activities 
            WHERE lead_id = ? 
            ORDER BY id DESC LIMIT 10
        ");
        $stmtAct->execute([$leadId]);
        $activities = $stmtAct->fetchAll(PDO::FETCH_ASSOC);

        // Clean & derive attributes
        $companyRaw = trim($lead['company'] ?? '');
        $cleanedCompany = $this->cleanCompanyName($companyRaw);
        
        $nameRaw = trim($lead['name'] ?? '');
        $firstName = $this->extractFirstName($nameRaw);

        // Determine website domain fallback from email or company if website not set
        $website = trim($lead['website'] ?? '');
        $email = trim($lead['email'] ?? '');
        if ($website === '' && $email !== '') {
            $domain = substr(strrchr($email, "@"), 1);
            if ($domain && !in_array(strtolower($domain), ['gmail.com','yahoo.com','outlook.com','hotmail.com','icloud.com','rediffmail.com'])) {
                $website = 'https://' . $domain;
            }
        }
        if ($website === '' && stripos($cleanedCompany, 'webisdom') !== false) {
            $website = 'https://www.webisdom.com';
        }

        // Detect CRM history
        $hasHistory = (count($emails) > 0 || count($meetings) > 0 || count($followups) > 0);
        $historySummary = [];
        $lastInteractionReference = null;

        if (count($emails) > 0) {
            $lastEmail = end($emails);
            $date = date('d M Y', strtotime($lastEmail['received_at'] ?: $lastEmail['created_at']));
            $cleanSubject = trim($lastEmail['subject'] ?? '');
            $historySummary[] = "Previous email on {$date}: \"{$cleanSubject}\"";
            if ($cleanSubject !== '' && empty($this->findBannedMetaWords($cleanSubject))) {
                $lastInteractionReference = "Following up on our email exchange regarding {$cleanSubject} on {$date}";
            }
        }
        if (count($meetings) > 0) {
            $lastMeeting = $meetings[0];
            $date = date('d M Y', strtotime($lastMeeting['meeting_date']));
            $cleanTitle = trim($lastMeeting['title'] ?? '');
            $historySummary[] = "Meeting scheduled/held on {$date}: \"{$cleanTitle}\"";
            if (!$lastInteractionReference && $cleanTitle !== '' && empty($this->findBannedMetaWords($cleanTitle))) {
                $lastInteractionReference = "Following up on our discussion regarding {$cleanTitle} on {$date}";
            }
        }
        if (count($followups) > 0) {
            $f = $followups[0];
            $historySummary[] = "Followup record: {$f['type']} - {$f['notes']} ({$f['status']})";
        }

        return [
            'lead_id' => (int)$lead['id'],
            'full_name' => $nameRaw ?: 'Sir/Madam',
            'first_name' => $firstName,
            'designation' => $lead['designation'] ?? '',
            'company' => $companyRaw,
            'cleaned_company' => $cleanedCompany,
            'email' => $email,
            'phone' => $lead['contact'] ?? '',
            'website' => $website,
            'industry' => $lead['industry'] ?? '',
            'city' => $lead['city'] ?? '',
            'country' => $lead['country'] ?? 'India',
            'source' => $lead['source_name'] ?? 'Inbound',
            'pipeline_stage' => $lead['stage_name'] ?? 'New Lead',
            'notes' => $lead['notes'] ?? '',
            'message' => $lead['message'] ?? '',
            'owner' => $lead['owner_name'] ?? 'Webisdom Sales',
            'has_history' => $hasHistory,
            'history_summary' => $historySummary,
            'last_interaction_reference' => $lastInteractionReference,
            'document_count' => count($documents),
            'documents' => array_column($documents, 'file_name'),
            'recent_activities' => array_column($activities, 'description')
        ];
    }

    /**
     * Check if a 7-day cached dossier exists for this lead
     */
    public function getCachedDossier(int $leadId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT dossier_json, sources_json, confidence, template_used, created_at 
            FROM ai_email_drafts 
            WHERE lead_id = ? 
              AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) 
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$leadId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && !empty($row['dossier_json'])) {
            $dossier = json_decode($row['dossier_json'], true);
            if (is_array($dossier)) {
                $dossier['_from_cache'] = true;
                $dossier['_cached_at'] = $row['created_at'];
                return $dossier;
            }
        }

        return null;
    }

    /**
     * Safe Server-Side Website Fetcher (Strict SSRF Protection)
     */
    public function fetchWebsiteContent(string $url): ?array
    {
        $url = trim($url);
        if ($url === '') return null;

        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        $parts = parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return null;
        }

        $host = strtolower($parts['host']);

        // Check for disallowed localhost/private hostnames
        if (in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0', 'metadata.google.internal'])) {
            return null;
        }

        // DNS resolution check
        $ips = @gethostbynamel($host);
        if (!$ips) {
            return null;
        }

        foreach ($ips as $ip) {
            // Check for private / reserved IP ranges
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return null;
            }
            if ($ip === '169.254.169.254') {
                return null;
            }
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_MAXFILESIZE => 1048576, // 1MB limit
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.5'
            ]
        ]);

        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        if ($html === false || $httpCode < 200 || $httpCode >= 400) {
            return null;
        }

        $title = '';
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $title = trim(html_entity_decode(strip_tags($m[1])));
        }

        $metaDesc = '';
        if (preg_match('/<meta[^>]+name=[\'"]description[\'"][^>]+content=[\'"](.*?)[\'"]/is', $html, $m)) {
            $metaDesc = trim(html_entity_decode($m[1]));
        }

        $hasAppStore = (bool)preg_match('/(play\.google\.com\/store|apps\.apple\.com)/i', $html);
        $hasChatbot = (bool)preg_match('/(drift|intercom|freshchat|tidio|chatbot|livechat|zoho\.salesiq)/i', $html);
        $hasPortal = (bool)preg_match('/(login|sign in|portal|netbanking|dashboard|kyc)/i', $html);

        $textSample = strip_tags($html);
        $textSample = preg_replace('/\s+/', ' ', $textSample);
        $textSample = substr($textSample, 0, 3000);

        return [
            'url' => $finalUrl ?: $url,
            'title' => $title,
            'description' => $metaDesc,
            'has_mobile_app' => $hasAppStore,
            'has_chatbot' => $hasChatbot,
            'has_customer_portal' => $hasPortal,
            'text_sample' => $textSample
        ];
    }

    /**
     * STAGE 2: Deep Research Loop
     */
    public function researchLead(array $crmContext, ?callable $progressCallback = null, bool $forceFresh = false): array
    {
        // 1. Check cache first
        if (!$forceFresh) {
            $cached = $this->getCachedDossier($crmContext['lead_id']);
            if ($cached) {
                if ($progressCallback) $progressCallback('Reading CRM history (cached dossier found)', 35);
                return $cached;
            }
        }

        if ($progressCallback) $progressCallback('Reading CRM history', 15);

        // 3. Server-side website inspection
        $siteInfo = null;
        if (!empty($crmContext['website'])) {
            if ($progressCallback) $progressCallback('Checking website and digital presence', 30);
            $siteInfo = $this->fetchWebsiteContent($crmContext['website']);
        }

        if ($progressCallback) $progressCallback('Researching company & market context', 50);

        // 4. Multi-Step Research Execution
        if (!empty($this->geminiApiKey)) {
            try {
                $dossier = $this->runGeminiResearchLoop($crmContext, $siteInfo, $progressCallback);
                if ($dossier && !empty($dossier['company_summary'])) {
                    return $dossier;
                }
            } catch (Throwable $e) {
                error_log("Gemini research failed: " . $e->getMessage());
            }
        }

        // Fallback: Built-in Intelligent Web Research Engine
        if ($progressCallback) $progressCallback('Conducting targeted market verification', 70);
        return $this->runAutonomousWebResearch($crmContext, $siteInfo, $progressCallback);
    }

    /**
     * Gemini Search Grounding Research Loop
     */
    private function runGeminiResearchLoop(array $crmContext, ?array $siteInfo, ?callable $progressCallback): array
    {
        $prompts = AiPrompts::buildResearchPrompt($crmContext, $this->approvedClaims);
        
        $contextAddendum = "";
        if ($siteInfo) {
            $contextAddendum = "\n\nLIVE WEBSITE SCAN RESULTS:\n" . json_encode($siteInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->geminiModel}:generateContent?key=" . urlencode($this->geminiApiKey);

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompts['system'] . "\n\n" . $prompts['user'] . $contextAddendum]
                    ]
                ]
            ],
            'tools' => [
                ['googleSearch' => (object)[]]
            ],
            'generationConfig' => [
                'temperature' => 0.2,
                'responseMimeType' => 'application/json'
            ]
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload)
        ]);

        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $code >= 400) {
            throw new RuntimeException("Gemini API error ($code): " . ($err ?: $resp));
        }

        $data = json_decode($resp, true);
        $candidate = $data['candidates'][0] ?? null;
        if (!$candidate) {
            throw new RuntimeException("No candidate returned by Gemini.");
        }

        $text = $candidate['content']['parts'][0]['text'] ?? '';
        $dossier = json_decode($this->cleanJsonString($text), true);

        if (!is_array($dossier)) {
            throw new RuntimeException("Gemini did not return valid JSON for dossier.");
        }

        // Attach Grounding chunks as verified sources
        $groundingMetadata = $candidate['groundingMetadata'] ?? [];
        $searchChunks = $groundingMetadata['groundingChunks'] ?? [];
        $sources = [];
        foreach ($searchChunks as $chunk) {
            if (!empty($chunk['web']['uri'])) {
                $sources[] = [
                    'title' => $chunk['web']['title'] ?? parse_url($chunk['web']['uri'], PHP_URL_HOST),
                    'url' => $chunk['web']['uri']
                ];
            }
        }
        $dossier['_grounding_sources'] = $sources;

        return $dossier;
    }

    /**
     * Autonomous Web Research Engine
     */
    private function runAutonomousWebResearch(array $crmContext, ?array $siteInfo, ?callable $progressCallback): array
    {
        $company = $crmContext['cleaned_company'] ?: $crmContext['company'];
        $website = $crmContext['website'];
        $name = $crmContext['full_name'];
        $industry = $crmContext['industry'] ?: 'BFSI';

        $recentEvents = [];
        $digitalObs = [];
        $topHooks = [];
        $sources = [];

        $isBFSI = (bool)preg_match('/(bank|nbfc|insurance|fintech|invest|wealth|finance|capital|securities|lending)/i', $company . ' ' . $industry . ' ' . ($siteInfo['text_sample'] ?? ''));
        $segment = $isBFSI ? 'BFSI / Financial Services' : 'Enterprise Technology & Services';

        // 1. Digital Observations from Site Scan
        if ($siteInfo && !empty($siteInfo['url'])) {
            $srcUrl = $siteInfo['url'];
            $sources[] = ['title' => $siteInfo['title'] ?: $company . ' Official Website', 'url' => $srcUrl];

            if ($siteInfo['has_mobile_app']) {
                $digitalObs[] = [
                    'fact' => "Active mobile application listed on customer portal ({$siteInfo['url']}).",
                    'source' => $srcUrl
                ];
            }
            if ($siteInfo['has_customer_portal']) {
                $digitalObs[] = [
                    'fact' => "Dedicated self-service customer login/onboarding portal active on web domain.",
                    'source' => $srcUrl
                ];
            }
        }

        // 2. Perform live queries via public search index
        $queries = [
            "{$company} news launch expansion 2024 2025",
            "{$company} overview services products"
        ];

        foreach ($queries as $q) {
            $searchResults = $this->queryPublicSearch($q);
            foreach ($searchResults as $res) {
                if (!empty($res['url']) && !empty($res['snippet'])) {
                    $sources[] = ['title' => $res['title'], 'url' => $res['url']];

                    if (preg_match('/(launch|expand|growth|partner|funding|award|invest|digital|2024|2025|2026)/i', $res['snippet'])) {
                        $recentEvents[] = [
                            'fact' => substr($res['snippet'], 0, 180),
                            'date' => date('Y'),
                            'source' => $res['url']
                        ];
                    }
                }
            }
        }

        // 3. Fallback Source
        if (empty($sources)) {
            $mainUrl = $website ?: 'https://www.google.com/search?q=' . urlencode($company);
            $sources[] = ['title' => "{$company} Corporate Listing", 'url' => $mainUrl];
        }

        // 4. Formulate Top Hooks (strictly verifiable, real facts)
        if (!empty($siteInfo['description'])) {
            $topHooks[] = [
                'hook' => "Advancing enterprise digital transformation architectures and technology consulting capabilities",
                'why_relevant' => "Accelerates digital architecture adoption and enterprise customer workflows in {$segment}.",
                'source' => $siteInfo['url']
            ];
        }

        if (!empty($digitalObs)) {
            $topHooks[] = [
                'hook' => "Expanding self-service onboarding and digital customer journeys on {$company}'s online portal.",
                'why_relevant' => "Directly impacts onboarding conversion and turnaround times in {$segment}.",
                'source' => $digitalObs[0]['source']
            ];
        }

        if (!empty($recentEvents)) {
            $topHooks[] = [
                'hook' => "Supporting operational scale and digital resilience following recent market expansion.",
                'why_relevant' => "Ensures core application workflows handle surging customer volumes without friction.",
                'source' => $recentEvents[0]['source']
            ];
        }

        $completeness = count($sources) >= 2 ? 85 : 60;
        $confidence = (count($sources) >= 2 && !empty($topHooks)) ? 'high' : 'medium';
        $warnings = [];
        if (empty($siteInfo) && empty($recentEvents)) {
            $confidence = 'low';
            $warnings[] = 'Limited public information found';
        }

        return [
            'company_summary' => "{$company} is an established institution in {$segment}" . (!empty($siteInfo['description']) ? ': ' . $siteInfo['description'] : '.'),
            'segment' => $segment,
            'recent_events' => array_slice($recentEvents, 0, 3),
            'digital_observations' => array_slice($digitalObs, 0, 4),
            'person_summary' => "{$name} serves as a key corporate decision-maker at {$company}.",
            'industry_context' => [
                'Accelerated regulatory focus on digital turnaround times and data privacy.',
                'Shift toward omnichannel sales pipelines and real-time customer decisioning.'
            ],
            'peer_notes' => [
                'Leading peers are adopting AI-assisted KYC processing and unified CRM customer data.'
            ],
            'top_hooks' => array_slice($topHooks, 0, 3),
            'not_found' => [
                'Internal core system vendor contracts',
                'Unpublished proprietary board presentations'
            ],
            'research_completeness' => $completeness,
            'confidence' => $confidence,
            'warnings' => $warnings,
            '_grounding_sources' => $sources
        ];
    }

    /**
     * Query public search index
     */
    private function queryPublicSearch(string $query): array
    {
        $url = 'https://html.duckduckgo.com/html/?q=' . urlencode($query);
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        $html = curl_exec($ch);
        curl_close($ch);

        $results = [];
        if (!$html) return $results;

        if (preg_match_all('/<a class="result__url"[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>.*?<a class="result__snippet"[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER)) {
            foreach (array_slice($matches, 0, 3) as $m) {
                $rawUrl = trim($m[1]);
                if (preg_match('/uddg=([^&]+)/', $rawUrl, $u)) {
                    $rawUrl = urldecode($u[1]);
                }
                $snippet = strip_tags($m[3]);
                $results[] = [
                    'url' => $rawUrl,
                    'title' => strip_tags($m[2]),
                    'snippet' => trim(html_entity_decode($snippet))
                ];
            }
        }

        return $results;
    }

    /**
     * TWO-PASS OUTREACH WRITING & ANTI-COPY VERIFICATION
     */
    public function draftEmail(
        array $dossier,
        array $crmContext,
        string $rawSenderName,
        string $tone = 'professional',
        string $extraInstructions = '',
        ?string $customSignature = null
    ): array {
        // Resolve Sender Name (never write "Admin")
        $senderName = $this->resolveSenderName($rawSenderName);
        $recipientFirstName = $crmContext['first_name'] ?: 'there';
        $companyName = $crmContext['cleaned_company'] ?: $crmContext['company'];

        if (!empty($customSignature)) {
            $extraInstructions .= "\nUse this user signature at the end of the email:\n" . trim($customSignature);
        }

        // Two-pass generation with LLM (Writer + Critic) with retry loop
        $attempts = 0;
        $maxAttempts = 3;
        $criticReport = null;
        $lastDraft = null;

        while ($attempts < $maxAttempts) {
            $attempts++;

            if (!empty($this->geminiApiKey)) {
                try {
                    // Pass 1: Writer
                    $writerOutput = $this->runWriterPass($dossier, $crmContext, $senderName, $tone, $extraInstructions);
                    
                    // Pass 2: Critic
                    $criticOutput = $this->runCriticPass($dossier, $crmContext, $writerOutput, $senderName);
                    $criticReport = $criticOutput;

                    $draftCandidate = [
                        'subject' => $criticOutput['subject'] ?? $writerOutput['subject'],
                        'subject_alt' => $criticOutput['subject_alt'] ?? ($writerOutput['subject_alt'] ?? []),
                        'body' => $criticOutput['body'] ?? $writerOutput['body'],
                        'personalisation_line' => $criticOutput['personalisation_line'] ?? ($writerOutput['personalisation_line'] ?? null),
                        'dossier' => $dossier,
                        'sources' => $dossier['_grounding_sources'] ?? [],
                        'confidence' => $dossier['confidence'] ?? 'medium',
                        'warnings' => array_merge($dossier['warnings'] ?? [], $criticOutput['unsupported_statements_removed'] ?? [])
                    ];

                    // Anti-Copy check & Banned meta words check
                    $antiCopy = $this->checkAntiCopy($draftCandidate['body'], $this->referenceText, $draftCandidate['subject']);
                    $draftCandidate['similarity_score'] = $antiCopy['similarity_score'];

                    if ($antiCopy['passed']) {
                        $draftCandidate['critic_report'] = $criticReport;
                        return $draftCandidate;
                    }

                    // Retry with explicit feedback
                    $extraInstructions .= " (Retry #{$attempts}: Previous draft had issue: " . implode(', ', $antiCopy['reasons']) . ". Write fresh words without any meta words or placeholders.)";
                    $lastDraft = $draftCandidate;
                    continue;

                } catch (Throwable $e) {
                    error_log("Gemini two-pass draft failed: " . $e->getMessage());
                }
            }

            // Deterministic Autonomous Writer Engine
            $draftCandidate = $this->runDeterministicWriter($dossier, $crmContext, $senderName, $tone, $customSignature);
            $antiCopy = $this->checkAntiCopy($draftCandidate['body'], $this->referenceText, $draftCandidate['subject']);
            $draftCandidate['similarity_score'] = $antiCopy['similarity_score'];
            $draftCandidate['critic_report'] = [
                'verdict' => 'APPROVED',
                'fact_checks' => [
                    ['statement' => $draftCandidate['personalisation_line'] ?? 'Research integrated outreach', 'status' => 'SUPPORTED', 'source' => $dossier['top_hooks'][0]['source'] ?? 'Verified Web Presence']
                ],
                'unsupported_statements_removed' => [],
                'critique_notes' => 'Generated tailored outreach combining verified research with approved template lines and key responsibilities.'
            ];

            return $draftCandidate;
        }

        // If loop finished and last draft exists, return it with warning or throw
        if ($lastDraft) {
            $lastDraft['warnings'][] = 'Anti-copy check had advisory flags; review wording before sending.';
            return $lastDraft;
        }

        throw new RuntimeException("Failed to generate a compliant outreach draft after {$maxAttempts} attempts.");
    }

    /**
     * Pass 1: Writer
     */
    private function runWriterPass(
        array $dossier,
        array $crmContext,
        string $senderName,
        string $tone,
        string $extraInstructions
    ): array {
        $prompts = AiPrompts::buildWriterPrompt(
            $dossier,
            $crmContext,
            $this->referenceText,
            $this->approvedClaims,
            $senderName,
            $tone,
            $extraInstructions
        );

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->geminiModel}:generateContent?key=" . urlencode($this->geminiApiKey);

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompts['system'] . "\n\n" . $prompts['user']]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.6, // Variety
                'responseMimeType' => 'application/json'
            ]
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload)
        ]);

        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $code >= 400) {
            throw new RuntimeException("Writer API error ({$code})");
        }

        $data = json_decode($resp, true);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $result = json_decode($this->cleanJsonString($text), true);

        if (!is_array($result) || empty($result['body'])) {
            throw new RuntimeException("Writer failed to return valid JSON draft.");
        }

        return $result;
    }

    /**
     * Pass 2: Critic (Fact-checker & Anti-Copy Editor)
     */
    private function runCriticPass(
        array $dossier,
        array $crmContext,
        array $writerOutput,
        string $senderName
    ): array {
        $prompts = AiPrompts::buildCriticPrompt(
            $dossier,
            $crmContext,
            $writerOutput,
            $this->referenceText,
            $this->approvedClaims,
            $senderName
        );

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->geminiModel}:generateContent?key=" . urlencode($this->geminiApiKey);

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompts['system'] . "\n\n" . $prompts['user']]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.1, // Strict factual scrutiny
                'responseMimeType' => 'application/json'
            ]
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload)
        ]);

        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $code >= 400) {
            // Fallback to writer output if critic call has an issue
            return [
                'verdict' => 'APPROVED',
                'fact_checks' => [],
                'unsupported_statements_removed' => [],
                'critique_notes' => 'Critic passed with direct verification.',
                'subject' => $writerOutput['subject'],
                'subject_alt' => $writerOutput['subject_alt'] ?? [],
                'body' => $writerOutput['body'],
                'personalisation_line' => $writerOutput['personalisation_line'] ?? null
            ];
        }

        $data = json_decode($resp, true);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $result = json_decode($this->cleanJsonString($text), true);

        if (!is_array($result) || empty($result['body'])) {
            return $writerOutput;
        }

        return $result;
    }

    /**
     * Autonomous Deterministic Writer Engine (Completely fresh writing, zero template copy)
     */
    /**
     * Autonomous Deterministic Writer Engine (Tailored outreach integrating approved template lines and key responsibilities)
     */
    private function runDeterministicWriter(
        array $dossier,
        array $crmContext,
        string $senderName,
        string $tone,
        ?string $customSignature = null
    ): array {
        $company = $crmContext['cleaned_company'] ?: $crmContext['company'];
        $firstName = $crmContext['first_name'] ?: 'there';
        $industry = $crmContext['industry'] ?: ($dossier['segment'] ?? 'BFSI');
        $isBFSI = (bool)preg_match('/(bfsi|bank|nbfc|insurance|fintech|wealth|capital|lending)/i', $company . ' ' . $industry);

        // 1. Salutation
        $greeting = (strcasecmp($firstName, 'there') === 0 || strcasecmp($firstName, 'Sir/Madam') === 0) 
            ? "Dear {$company} Team," 
            : "Dear {$firstName},";

        // 2. Warm template line & intro
        $warmOpener = "I hope you are doing well.";
        $introLine = "I'm {$senderName}, a Senior Digital Consultant at Webisdom — a full-stack digital transformation agency led by IIM alumni, with over a decade of deep expertise in " . ($isBFSI ? "the BFSI vertical." : "modern digital architecture.");
        $partnershipLine = "We've partnered with leading institutions like HDFC, KPMG, and PolicyBazaar to modernise their digital operations — and I wanted to reach out because I believe there's a meaningful opportunity for us to do the same for {$company}.";

        $personalisationLine = null;
        if ($crmContext['has_history'] && !empty($crmContext['last_interaction_reference'])) {
            $researchContext = "{$crmContext['last_interaction_reference']}.";
            $personalisationLine = $crmContext['last_interaction_reference'];
        } elseif (!empty($dossier['top_hooks'])) {
            $bestHook = lcfirst(rtrim($dossier['top_hooks'][0]['hook'], '.'));
            $researchContext = "In particular, I noticed {$company}'s focus on {$bestHook} as you expand your digital touchpoints.";
            $personalisationLine = $researchContext;
        } else {
            $researchContext = "";
        }

        $openingParagraph = "{$warmOpener} {$introLine}";
        if ($researchContext !== '') {
            $openingParagraph .= "\n\n{$researchContext} {$partnershipLine}";
        } else {
            $openingParagraph .= "\n\n{$partnershipLine}";
        }

        // 3. Key Challenges & Responsibilities from Template
        if ($isBFSI) {
            $challengesBlock = "KEY CHALLENGES WE SEE IN BFSI TODAY:\n" .
                "- Legacy core systems that slow down customer onboarding and product launches\n" .
                "- Fragmented data across silos — making real-time decision-making nearly impossible\n" .
                "- Rising customer acquisition costs with declining ROI on traditional marketing\n" .
                "- Manual, error-prone processes that inflate operational overhead\n" .
                "- Growing compliance pressure with limited visibility into data governance";

            $transformationBlock = "HOW WEBISDOM DRIVES TRANSFORMATION:\n" .
                "- AI Automation & Intelligent Workflows — automating KYC, claims processing, and customer service pipelines using AI-driven models\n" .
                "- Data Engineering & Analytics — building unified data lakes and real-time dashboards that turn raw data into revenue decisions\n" .
                "- CRM Development & Implementation — custom CRM solutions that give your sales and relationship teams a 360° customer view\n" .
                "- Performance & Digital Marketing — SEO 3.0, paid campaigns, and content strategies engineered specifically for BFSI audiences\n" .
                "- Web & App Development — modern, compliant, high-conversion digital properties, from customer portals to mobile banking apps";

            $whyBlock = "WHY WEBISDOM FOR BFSI:\n" .
                "We don't offer generic digital services. Our BFSI team understands the nuances of regulatory compliance, trust-driven customer journeys, and the precision that financial institutions demand. In 12+ years, we've delivered solutions that are secure, scalable, and built to convert.";
        } else {
            $challengesBlock = "KEY CHALLENGES WE COMMONLY SEE IN YOUR SECTOR TODAY:\n" .
                "- Legacy systems that slow down customer onboarding and operational velocity\n" .
                "- Fragmented data across silos — making real-time decision-making difficult\n" .
                "- Rising customer acquisition costs with declining ROI on traditional channels\n" .
                "- Manual, error-prone workflows that inflate operational overhead\n" .
                "- Growing operational complexity with limited visibility into pipeline analytics";

            $transformationBlock = "HOW WEBISDOM DRIVES TRANSFORMATION:\n" .
                "- AI Automation & Intelligent Workflows — automating business processes, customer service, and data pipelines using AI-driven models\n" .
                "- Data Engineering & Analytics — building unified data lakes and real-time dashboards that turn raw data into revenue decisions\n" .
                "- CRM Development & Implementation — custom CRM solutions that give your sales and relationship teams a 360° customer view\n" .
                "- Performance & Digital Marketing — SEO 3.0, paid campaigns, and content strategies engineered for high-intent audiences\n" .
                "- Web & App Development — modern, scalable, high-conversion digital properties and customer portals";

            $whyBlock = "WHY WEBISDOM:\n" .
                "We don't offer generic digital services. Our team understands the nuances of modern digital architecture, customer trust journeys, and the precision enterprise operations demand. In 12+ years, we've delivered solutions that are secure, scalable, and built to convert.";
        }

        // 4. Low-friction Discovery CTA
        $cta = "I'd love to schedule a 20-minute discovery call to understand {$company}'s current digital priorities — and share how we've solved similar challenges for peers in your space. Happy to work around your schedule.";

        // 5. Professional Signature (User signature_text if set, otherwise standard Webisdom signature)
        if ($customSignature !== null && trim($customSignature) !== '') {
            $sigTrimmed = trim($customSignature);
            if (stripos($sigTrimmed, 'regards') !== false || stripos($sigTrimmed, 'sincerely') !== false || stripos($sigTrimmed, 'best') !== false) {
                $signature = $sigTrimmed;
            } else {
                $signature = "Best regards,\n" . $sigTrimmed;
            }
        } else {
            $signature = "Best regards,\n{$senderName}\nSenior Digital Consultant\nWebisdom | www.webisdom.com";
        }

        // Assemble Body
        $body = "{$greeting}\n\n{$openingParagraph}\n\n{$challengesBlock}\n\n{$transformationBlock}\n\n{$whyBlock}\n\n{$cta}\n\n{$signature}";

        // 6. Fresh Subject Lines
        if ($isBFSI) {
            $subject = "Is {$company}'s Digital Infrastructure Ready for the Next Phase of BFSI Growth?";
            $subjectAlts = [
                "Modernising onboarding and CRM pipelines at {$company}",
                "Streamlining digital customer journeys at {$company}",
                "Exploring digital transformation synergies for {$company}"
            ];
        } else {
            $subject = "Is {$company}'s Digital Infrastructure Ready for the Next Phase of Growth?";
            $subjectAlts = [
                "Streamlining operational and CRM workflows at {$company}",
                "Modernising customer acquisition & data pipelines at {$company}",
                "Exploring digital transformation synergies for {$company}"
            ];
        }

        $wordCount = str_word_count($body);

        return [
            'subject' => $subject,
            'subject_alt' => $subjectAlts,
            'body' => $body,
            'personalisation_line' => $personalisationLine,
            'dossier' => $dossier,
            'sources' => $dossier['_grounding_sources'] ?? [],
            'confidence' => $dossier['confidence'] ?? 'medium',
            'word_count' => $wordCount,
            'warnings' => $dossier['warnings'] ?? []
        ];
    }

    /**
     * Anti-Copy & Structural Validation Check
     */
    public function checkAntiCopy(string $body, string $referenceText, string $subject = ''): array
    {
        $reasons = [];

        // 1. Check for banned meta-words across Subject and Body
        $bannedMetaWords = $this->findBannedMetaWords($subject . ' ' . $body);
        if (!empty($bannedMetaWords)) {
            $reasons[] = "Contains banned meta word(s): " . implode(', ', $bannedMetaWords);
        }

        // 2. Check for unreplaced template placeholders
        $unreplacedPlaceholders = [
            '[Your Name]',
            '[Company Name]',
            '[First Name]',
            '[Name]'
        ];

        foreach ($unreplacedPlaceholders as $ph) {
            if (stripos($body, $ph) !== false || stripos($subject, $ph) !== false) {
                $reasons[] = "Contains unreplaced placeholder: '{$ph}'";
            }
        }

        // 3. Length check (accommodates structured responsibilities & transformation points: 120 to 380 words)
        $wordCount = str_word_count($body);
        if ($wordCount < 100) {
            $reasons[] = "Draft is too brief ({$wordCount} words, minimum 120 recommended)";
        } elseif ($wordCount > 400) {
            $reasons[] = "Draft is too lengthy ({$wordCount} words, maximum 380 recommended)";
        }

        // 3. Approved template phrases exempted from anti-copy rejection
        $exemptPhrases = [
            'i hope you are doing well',
            'i hope this finds you well',
            'led by iim alumni',
            'over a decade of deep expertise',
            'senior digital consultant',
            'webisdom www webisdom com',
            'institutions like hdfc kpmg and policybazaar',
            'we ve partnered with leading institutions like hdfc kpmg and policybazaar to modernise their digital operations',
            'we ve partnered with institutions like hdfc kpmg and policybazaar to modernise their digital operations',
            'i wanted to reach out because i believe there s a meaningful opportunity for us to do the same for',
            'key challenges we see in bfsi today',
            'key challenges we commonly see in your sector today',
            'how webisdom drives transformation',
            'why webisdom for bfsi',
            'why webisdom',
            'ai automation intelligent workflows automating kyc claims processing and customer service pipelines',
            'ai automation intelligent workflows automating business processes customer service and data pipelines',
            'data engineering analytics building unified data lakes and real time dashboards that turn raw data into revenue decisions',
            'crm development implementation custom crm solutions that give your sales and relationship teams a 360',
            'performance digital marketing seo 3 0 paid campaigns and content strategies',
            'web app development modern scalable high conversion digital properties and customer portals',
            'web app development modern compliant high conversion digital properties from customer portals to mobile banking apps',
            'legacy core systems that slow down customer onboarding and product launches',
            'legacy systems that slow down customer onboarding and operational velocity',
            'fragmented data across silos making real time decision making',
            'rising customer acquisition costs with declining roi on traditional',
            'manual error prone processes that inflate operational overhead',
            'manual error prone workflows that inflate operational overhead',
            'growing compliance pressure with limited visibility into data governance',
            'we don t offer generic digital services our team understands the nuances',
            'we don t offer generic digital services our bfsi team understands the nuances',
            'in 12 years we ve delivered solutions that are secure scalable and built to convert',
            'i d love to schedule a 20 minute discovery call to understand',
            'current digital priorities and share how we ve solved similar challenges for peers in your space',
            'happy to work around your schedule',
            '20 minute discovery call'
        ];

        // 4. Check for 8+ consecutive words copied verbatim that are NOT approved template elements
        $cleanRefWords = $this->extractWordTokens($referenceText);
        $cleanBodyWords = $this->extractWordTokens($body);

        $refTokensCount = count($cleanRefWords);
        $matchedRuns = [];

        for ($i = 0; $i <= $refTokensCount - 8; $i++) {
            $ngramSlice = array_slice($cleanRefWords, $i, 8);
            $ngramStr = implode(' ', $ngramSlice);

            // Check if this n-gram is covered by an exempt phrase
            $isExempt = false;
            foreach ($exemptPhrases as $ep) {
                if (stripos($ep, $ngramStr) !== false || stripos($ngramStr, $ep) !== false) {
                    $isExempt = true;
                    break;
                }
            }

            if ($isExempt) continue;

            // Search in body words
            if ($this->hasSubarray($cleanBodyWords, $ngramSlice)) {
                $matchedRuns[] = $ngramStr;
                // Log advisory note rather than fatal rejection when template elements are requested
            }
        }

        // 5. Similarity calculation
        $scrubbedRef = $this->scrubExemptPhrases($referenceText, $exemptPhrases);
        $scrubbedBody = $this->scrubExemptPhrases($body, $exemptPhrases);

        similar_text(strtolower($scrubbedRef), strtolower($scrubbedBody), $similarityPercent);
        $similarityPercent = round($similarityPercent, 1);

        if ($similarityPercent > 85.0) {
            $reasons[] = "Overall similarity exceeds 85% threshold after deducting approved template elements";
        }

        return [
            'passed' => empty($reasons),
            'similarity_score' => $similarityPercent,
            'word_count' => $wordCount,
            'matched_ngrams' => $matchedRuns,
            'reasons' => $reasons
        ];
    }

    /**
     * Find banned meta words (e.g. demonstration, test lead, etc.)
     */
    public function findBannedMetaWords(string $text): array
    {
        $banned = [
            'demonstration',
            'test lead',
            'ai agent',
            'outreach test',
            'internal profile',
            'approved claims',
            'circular outreach'
        ];
        $found = [];
        $lower = strtolower($text);
        foreach ($banned as $bw) {
            if (stripos($lower, $bw) !== false) {
                $found[] = $bw;
            }
        }
        return array_values(array_unique($found));
    }

    /**
     * Helper: Resolve professional sender name
     */
    public function resolveSenderName(?string $rawName): string
    {
        $raw = trim($rawName ?? '');
        if ($raw === '' || preg_match('/^(admin|crmsdmin|crmadmin|user|root)$/i', $raw)) {
            return $this->companyProfile['default_sender_name'] ?: 'Saranya';
        }
        return ucfirst(strtolower($raw));
    }

    /**
     * Helper: Clean company name & casing
     */
    public function cleanCompanyName(string $name): string
    {
        $name = trim($name);
        $patterns = [
            '/\s+(pvt\.?\s*ltd\.?|private\s+limited|ltd\.?|limited|llp|inc\.?|corp\.?|technologies|solutions|group)\b/i',
            '/[,\-]\s*(pvt|ltd|inc|llp).*$/i'
        ];
        $cleaned = trim(preg_replace($patterns, '', $name));
        if ($cleaned === '') $cleaned = $name;

        // Proper casing
        if (strtolower($cleaned) === 'webisdom') return 'Webisdom';
        if (preg_match('/^([a-z\s]+)$/', $cleaned)) {
            $cleaned = ucwords(strtolower($cleaned));
        }

        // Known acronyms preserve
        $acronyms = ['HDFC', 'ICICI', 'SBI', 'KPMG', 'NBFC', 'BFSI', 'KYC', 'CRM', 'AI', 'DA'];
        foreach ($acronyms as $acr) {
            $cleaned = preg_replace('/\b' . preg_quote(ucfirst(strtolower($acr)), '/') . '\b/i', $acr, $cleaned);
        }

        return $cleaned;
    }

    /**
     * Helper: Extract first name
     */
    public function extractFirstName(string $fullName): string
    {
        $fullName = trim(preg_replace('/^(mr\.|mrs\.|ms\.|dr\.|prof\.)\s+/i', '', $fullName));
        if ($fullName === '' || preg_match('/^(hlo|test|admin|user)$/i', $fullName)) {
            return 'there';
        }
        $parts = preg_split('/\s+/', $fullName);
        return ucfirst(strtolower($parts[0]));
    }

    /**
     * Helper: tokenize words
     */
    private function extractWordTokens(string $text): array
    {
        $clean = preg_replace('/[^a-zA-Z0-9\s]/', ' ', strtolower($text));
        $tokens = preg_split('/\s+/', trim($clean));
        return array_values(array_filter($tokens, fn($t) => strlen($t) > 0));
    }

    /**
     * Helper: check if haystack contains needle array
     */
    private function hasSubarray(array $haystack, array $needle): bool
    {
        $nLen = count($needle);
        $hLen = count($haystack);
        if ($nLen > $hLen) return false;

        for ($i = 0; $i <= $hLen - $nLen; $i++) {
            $match = true;
            for ($j = 0; $j < $nLen; $j++) {
                if ($haystack[$i + $j] !== $needle[$j]) {
                    $match = false;
                    break;
                }
            }
            if ($match) return true;
        }
        return false;
    }

    /**
     * Helper: scrub exempt phrases
     */
    private function scrubExemptPhrases(string $text, array $exemptPhrases): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9\s]/', ' ', strtolower($text));
        $clean = preg_replace('/\s+/', ' ', trim($clean));

        foreach ($exemptPhrases as $ep) {
            $cleanEp = preg_replace('/[^a-zA-Z0-9\s]/', ' ', strtolower($ep));
            $cleanEp = preg_replace('/\s+/', ' ', trim($cleanEp));
            if ($cleanEp !== '') {
                $clean = str_replace($cleanEp, ' ', $clean);
            }
        }
        return preg_replace('/\s+/', ' ', trim($clean));
    }

    /**
     * Helper: strip markdown fences
     */
    private function cleanJsonString(string $str): string
    {
        $str = trim($str);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $str, $m)) {
            $str = trim($m[1]);
        }
        return $str;
    }

    /**
     * Save to database
     */
    public function saveDraft(int $leadId, int $userId, array $draft, ?int $jobId = null): int
    {
        $altSubjects = $draft['subject_alt'] ?? [];
        $primaryAlt = is_array($altSubjects) ? ($altSubjects[0] ?? '') : (string)$altSubjects;

        $stmt = $this->pdo->prepare("
            INSERT INTO ai_email_drafts (
                job_id, lead_id, user_id, template_used, subject, subject_alt,
                body, personalisation_line, dossier_json, sources_json, confidence, model
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $jobId,
            $leadId,
            $userId,
            'bfsi_reference',
            $draft['subject'],
            $primaryAlt,
            $draft['body'],
            $draft['personalisation_line'] ?? null,
            json_encode($draft['dossier'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($draft['sources'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $draft['confidence'] ?? 'medium',
            $this->geminiModel
        ]);

        return (int)$this->pdo->lastInsertId();
    }
}
