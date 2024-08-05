<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Fehlerbehandlung und Logging-Einstellungen
ini_set('display_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Autoload-Dateien einbinden
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
} else {
    error_log("Autoload file not found at: " . $autoloadPath);
    http_response_code(500);
    echo json_encode(['error' => 'Autoload file not found']);
    exit;
}

// Überprüfen, ob die Klassen geladen wurden
if (!class_exists('GuzzleHttp\Client')) {
    error_log("GuzzleHttp\Client class not found");
    http_response_code(500);
    echo json_encode(['error' => 'Required libraries not loaded']);
    exit;
}

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Dotenv\Dotenv;

// Umgebungsvariablen laden
$envPath = __DIR__ . '/..';
if (!file_exists($envPath . '/.env')) {
    error_log(".env file not found in: " . $envPath);
    http_response_code(500);
    echo json_encode(['error' => '.env file not found']);
    exit;
}

try {
    $dotenv = Dotenv::createImmutable($envPath);
    $dotenv->load();
    if (!isset($_ENV['ANTHROPIC_API_KEY'])) {
        throw new Exception("ANTHROPIC_API_KEY not set in .env file");
    }
    $api_key = $_ENV['ANTHROPIC_API_KEY'];
} catch (Exception $e) {
    error_log("Error loading environment variables: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error loading configuration: ' . $e->getMessage()]);
    exit;
}

// Logging-Funktionen
function logError($message) {
    error_log("[ERROR] " . $message);
}

function logInfo($message) {
    error_log("[INFO] " . $message);
}

function cleanString($str) {
    // Entfernt alle Steuerzeichen außer Zeilenumbrüche und Tabulatoren
    $str = preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/', '', $str);
    // Ersetze Zeilenumbrüche durch Leerzeichen
    $str = str_replace(["\r", "\n"], ' ', $str);
    // Entferne mehrfache Leerzeichen
    $str = preg_replace('/\s+/', ' ', $str);
    // Entferne eckige Klammern am Anfang und Ende des Strings
    $str = preg_replace('/^\[|\]$/', '', $str);
    return trim($str);
}

function analyzeWebsite($url) {
    global $api_key;
    
    // Funktion zum Extrahieren relevanter Teile
    function extractRelevantParts($html) {
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        $relevantParts = [];

        // Extrahiere Titel
        $title = $xpath->query('//title')->item(0);
        if ($title) {
            $relevantParts[] = "Title: " . $title->nodeValue;
        }

        // Extrahiere Meta-Beschreibung
        $metaDesc = $xpath->query('//meta[@name="description"]/@content')->item(0);
        if ($metaDesc) {
            $relevantParts[] = "Meta Description: " . $metaDesc->nodeValue;
        }

        // Extrahiere H1-Überschriften
        $h1s = $xpath->query('//h1');
        foreach ($h1s as $h1) {
            $relevantParts[] = "H1: " . $h1->nodeValue;
        }

        // Extrahiere die ersten paar Absätze
        $paragraphs = $xpath->query('//p');
        $paragraphCount = 0;
        foreach ($paragraphs as $p) {
            if ($paragraphCount >= 3) break;
            $relevantParts[] = "Paragraph: " . $p->nodeValue;
            $paragraphCount++;
        }

        return implode("\n\n", $relevantParts);
    }

    // Hole den Websiteinhalt
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $html = curl_exec($ch);
    if (curl_errno($ch)) {
        throw new Exception("Failed to retrieve website content. cURL Error: " . curl_error($ch));
    }
    curl_close($ch);

    // Extrahiere relevante Teile
    $relevantContent = extractRelevantParts($html);

    // Begrenze die Länge auf maximal 4000 Zeichen
    $limitedContent = substr($relevantContent, 0, 4000);

    $client = new \GuzzleHttp\Client();
    
    try {
        $systemPrompt = file_get_contents(__DIR__ . '/../prompts/prompt1.md') . "\n" . file_get_contents(__DIR__ . '/../prompts/prompt2.md');
        
        $response = $client->post('https://api.anthropic.com/v1/messages', [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-API-Key' => $api_key,
                'anthropic-version' => '2023-06-01'
            ],
            'json' => [
                'model' => 'claude-3-sonnet-20240229',
                'max_tokens' => 1000,
                'system' => $systemPrompt,
                'messages' => [
                    ['role' => 'user', 'content' => "Analyze this website content for a UGC video marketplace:\n\n$limitedContent"],
                ],
            ],
        ]);
        
        $result = json_decode($response->getBody(), true);
        $analysis = $result['content'][0]['text'];
        
        error_log("API Response for analysis: " . $analysis);
        
        // Parse the analysis response
        $analysisResult = [
            'product' => '',
            'productName' => '',
            'targetAudience' => '',
            'callToAction' => '',
            'recommendedChannels' => '',
        ];
        
        if (preg_match('/Produkt\/Dienstleistung:?\s*(.+?)(?:\n|$)/i', $analysis, $match)) {
            $analysisResult['product'] = cleanString(trim($match[1]));
        }
        if (preg_match('/Produktname:?\s*(.+?)(?:\n|$)/i', $analysis, $match)) {
            $analysisResult['productName'] = cleanString(trim($match[1]));
        }
        if (preg_match('/Zielgruppe:?\s*(.+?)(?:\n|$)/i', $analysis, $match)) {
            $analysisResult['targetAudience'] = cleanString(trim($match[1]));
        }
        if (preg_match('/Call to Action:?\s*(.+?)(?:\n|$)/i', $analysis, $match)) {
            $analysisResult['callToAction'] = cleanString(trim($match[1]));
        }
        if (preg_match('/Empfohlene Netzwerke:?\s*(.+?)(?:\n|$)/i', $analysis, $match)) {
            $analysisResult['recommendedChannels'] = cleanString(trim($match[1]));
        }
        
        return $analysisResult;
    } catch (\GuzzleHttp\Exception\ClientException $e) {
        $errorBody = json_decode($e->getResponse()->getBody(), true);
        $errorMessage = $errorBody['error']['message'] ?? 'Unknown error occurred';
        throw new Exception("API Error: " . $errorMessage);
    } catch (Exception $e) {
        throw new Exception("An error occurred: " . $e->getMessage());
    }
}

function generateBriefingIdeas($analysis) {
    global $api_key;
    $client = new Client();
    
    try {
        $systemPrompt = file_get_contents('../prompts/prompt1.md') . "\n" . file_get_contents('../prompts/prompt3.md');
        
        $response = $client->post('https://api.anthropic.com/v1/messages', [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-API-Key' => $api_key,
                'anthropic-version' => '2023-06-01'
            ],
            'json' => [
                'model' => 'claude-3-sonnet-20240229',
                'max_tokens' => 1000,
                'system' => $systemPrompt,
                'messages' => [
                    ['role' => 'user', 'content' => "Generate 3 brief UGC video ideas based on this analysis. Each idea should have a title, a short description (max 50 words), duration, and recommended channels. Format as follows:\n\nTitle: [Title]\nDescription: [Short description]\nDuration: [Duration]\nChannels: [Channels]\n\nAnalysis:\n" . json_encode($analysis)],
                ],
            ],
        ]);
        
        $result = json_decode($response->getBody(), true);
        
        error_log("Full API Response: " . json_encode($result, JSON_UNESCAPED_UNICODE));
        
        if (!isset($result['content']) || !is_array($result['content']) || empty($result['content'])) {
            throw new Exception("Unexpected API response structure");
        }
        
        $content = $result['content'][0]['text'];
        
        error_log("API Response Content: " . $content);

        $ideas = [];

        // Versuche verschiedene Parsing-Methoden
        $patterns = [
            '/Title: (.*?)\nDescription: (.*?)\nDuration: (.*?)\nChannels: (.*?)(?=\n\nTitle:|$)/s',
            '/(\d+\.\s*.*?)(?=\d+\.\s*|\z)/s'
        ];

        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);
            if (!empty($matches)) {
                foreach ($matches as $match) {
                    if (count($match) === 5) {
                        $ideas[] = [
                            'title' => cleanString(trim($match[1])),
                            'description' => cleanString(trim($match[2])),
                            'duration' => cleanString(trim($match[3])),
                            'channels' => cleanString(trim($match[4]))
                        ];
                    } else {
                        $idea = $match[0];
                        preg_match('/(?:Title:|^\d+\.)\s*(.+?)(?:\n|$)/i', $idea, $titleMatch);
                        preg_match('/Description:?\s*(.+?)(?=\n(?:Duration|Channels):|$)/is', $idea, $descMatch);
                        preg_match('/Duration:?\s*(.+?)(?=\n|$)/i', $idea, $durationMatch);
                        preg_match('/Channels:?\s*(.+?)(?=\n|$)/i', $idea, $channelsMatch);

                        $ideas[] = [
                            'title' => cleanString(trim($titleMatch[1] ?? "")),
                            'description' => cleanString(trim($descMatch[1] ?? "")),
                            'duration' => cleanString(trim($durationMatch[1] ?? "")),
                            'channels' => cleanString(trim($channelsMatch[1] ?? ""))
                        ];
                    }
                }
                break;  // Wenn Ideen gefunden wurden, brechen wir die Schleife ab
            }
        }

        error_log("Extracted ideas: " . json_encode($ideas, JSON_UNESCAPED_UNICODE));

        if (empty($ideas)) {
            throw new Exception("Failed to parse ideas from API response");
        }

        return $ideas;
    } catch (ClientException $e) {
        $errorBody = json_decode($e->getResponse()->getBody(), true);
        $errorMessage = $errorBody['error']['message'] ?? 'Unknown error occurred';
        error_log("API Error: " . $errorMessage);
        throw new Exception("API Error: " . $errorMessage);
    } catch (Exception $e) {
        error_log("Error in generateBriefingIdeas: " . $e->getMessage());
        throw $e;
    }
}

function generateFullBriefing($ideaIndex, $analysis, $idea) {
    global $api_key;
    $client = new Client();
    
    try {
        $systemPrompt = file_get_contents('../prompts/prompt4.md');
        
        $response = $client->post('https://api.anthropic.com/v1/messages', [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-API-Key' => $api_key,
                'anthropic-version' => '2023-06-01'
            ],
            'json' => [
                'model' => 'claude-3-sonnet-20240229',
                'max_tokens' => 2000,
                'system' => $systemPrompt,
                'messages' => [
                    ['role' => 'user', 'content' => "Generate a full briefing for this idea:\n\n" . json_encode($idea) . "\n\nBased on this analysis:\n\n" . json_encode($analysis)],
                ],
            ],
        ]);
        
        $result = json_decode($response->getBody(), true);
        $briefing = $result['content'][0]['text'];
        
        // Konvertiere Markdown zu HTML
        $parsedown = new \Parsedown();
        $briefingHtml = $parsedown->text($briefing);
        
        return $briefingHtml;
    } catch (ClientException $e) {
        $errorBody = json_decode($e->getResponse()->getBody(), true);
        $errorMessage = $errorBody['error']['message'] ?? 'Unknown error occurred';
        throw new Exception("API Error: " . $errorMessage);
    } catch (Exception $e) {
        throw new Exception("An error occurred: " . $e->getMessage());
    }
}

// Hauptteil des Skripts
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    try {
        if (isset($data['url'])) {
            logInfo("Analysiere Website: " . $data['url']);
            $analysis = analyzeWebsite($data['url']);
            logInfo("Website-Analyse abgeschlossen");
            
            $ideas = generateBriefingIdeas($analysis);
            logInfo("Briefing-Ideen generiert");
            
            echo json_encode([
                'analysis' => $analysis,
                'ideas' => $ideas,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif (isset($data['ideaIndex']) && isset($data['analysis']) && isset($data['idea'])) {
            logInfo("Generiere vollständiges Briefing für Idee " . $data['ideaIndex']);
            $fullBriefing = generateFullBriefing($data['ideaIndex'], $data['analysis'], $data['idea']);
            echo json_encode(['briefing' => $fullBriefing], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            throw new Exception('Invalid request');
        }
    } catch (Exception $e) {
        logError("Fehler: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    error_log("Final response: " . json_encode([
        'analysis' => $analysis,
        'ideas' => $ideas,
    ], JSON_UNESCAPED_UNICODE));
}