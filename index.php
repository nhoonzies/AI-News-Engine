<?php
// Load Composer's autoloader & libraries
require 'vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

date_default_timezone_set('Asia/Manila');

// Load the .env variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// PERSISTENCE: Track automation state and custom target time
$statusFile = 'scheduler_status.json';
if (!file_exists($statusFile)) {
    file_put_contents($statusFile, json_encode(['active' => false, 'target_time' => '10:00']));
}
$statusData = json_decode(file_get_contents($statusFile), true);

// Ensure target_time exists
if (!isset($statusData['target_time'])) {
    $statusData['target_time'] = '10:00';
}

// HANDLE AJAX REQUESTS (Toggle Automation or Update Time)
if (isset($_POST['toggle_scheduler'])) {
    $statusData['active'] = !$statusData['active'];
    file_put_contents($statusFile, json_encode($statusData));
    echo json_encode($statusData);
    exit;
}
if (isset($_POST['update_time'])) {
    $statusData['target_time'] = $_POST['update_time'];
    file_put_contents($statusFile, json_encode($statusData));
    echo json_encode($statusData);
    exit;
}

// Configuration for available news sources
$availableSources = [
    'yugatech' => ['name' => 'YugaTech', 'url' => 'https://www.yugatech.com/feed/'],
    'philstar' => ['name' => 'Philstar Headlines', 'url' => 'https://www.philstar.com/rss/headlines'],
    'abscbn'   => ['name' => 'ABS-CBN News', 'url' => 'https://news.abs-cbn.com/rss/news']
];

// Default UI States
$rssStatus = "<span class='badge idle'>Idle</span>";
$aiStatus = "<span class='badge idle'>Idle</span>";
$emailStatus = "<span class='badge idle'>Idle</span>";
$emailPreview = "";
$errorMessage = "";
$logMessage = "System ready. " . ($statusData['active'] ? "Automation monitoring for " . date("g:i A", strtotime($statusData['target_time'])) : "Select sources and a target recipient.");

// ENGINE LOGIC (Manual Click OR Automated Trigger)
if (isset($_POST['run_engine']) || isset($_GET['automate'])) {
    
    $startTime = date('h:i:s A');
    $logMessage = "Engine started at " . $startTime . "...<br>";

    // 1. DETERMINE NEWS SOURCES
    $activeSources = isset($_POST['news_sources']) ? $_POST['news_sources'] : ['yugatech', 'philstar', 'abscbn'];
    $numSources = count($activeSources);
    
    // ARTICLE MATH: 1 src = 6, 2 src = 3 each, 3 src = 2 each (Total 6)
    $maxItemsPerSource = ($numSources > 0) ? floor(6 / $numSources) : 6;
    if ($maxItemsPerSource < 1) $maxItemsPerSource = 1;

    // 2. DETERMINE RECIPIENT
    $targetEmail = $_ENV['SENDER_EMAIL'];
    if (isset($_POST['send_to']) && $_POST['send_to'] === 'others') {
        if (!empty($_POST['custom_email']) && filter_var($_POST['custom_email'], FILTER_VALIDATE_EMAIL)) {
            $targetEmail = $_POST['custom_email'];
            $logMessage .= "Target overridden: Sending to " . htmlspecialchars($targetEmail) . "...<br>";
        } else {
            $errorMessage = "Invalid custom email address provided.";
        }
    }

    if (empty($errorMessage)) {
        try {
            $aggregatedNews = "";
            $fetchedNames = [];

            foreach ($activeSources as $key) {
                if (isset($availableSources[$key])) {
                    $source = $availableSources[$key];
                    $rss = @simplexml_load_file($source['url']);
                    
                    if ($rss) {
                        $fetchedNames[] = $source['name'];
                        $count = 0;
                        $items = isset($rss->channel->item) ? $rss->channel->item : $rss->item;

                        foreach ($items as $item) {
                            if ($count >= $maxItemsPerSource) break; 
                            $aggregatedNews .= "Source: " . $source['name'] . "\nTitle: " . $item->title . "\nLink: " . $item->link . "\nSummary: " . strip_tags($item->description) . "\n---\n\n";
                            $count++;
                        }
                    }
                }
            }

            if (!empty($aggregatedNews)) {
                $rssStatus = "<span class='badge success'>Fetched: " . implode(', ', $fetchedNames) . "</span>";

                // 3. AI Draft - Strict Prompt to stop placeholders and filler
                $apiKey = $_ENV['GEMINI_API_KEY'];
                $client = new \GuzzleHttp\Client();
                $promptText = "You are an expert newsletter editor for the Philippine market, contextualized specifically for local businesses, tech professionals, and freelancers.
                Your final output must strictly follow a professional, highly readable format with clear indentation and vertical spacing.

                First, craft a professional and catchy subject line on the very first line. (Example: PH Business & Tech Digest: Innovation, Rights, and Policy).

                Next, write a brief, informative intro paragraph that contextualizes the themes from the data provided (e.g., 'Kamusta, Kabayan! This week we cover critical updates impacting national standing, digital security...').

                Then, for each news item provided, generate a formatted analysis. Every article section MUST be indented (use Markdown blockquotes '>' to create the indentation) and follow this exact structure with vertical spacing between elements:

                > **[Professional Article Title]**
                >
                > **Impact:** [Your concise analysis explaining WHY this news matters to Filipino businesses, tech professionals, and freelancers. Do not just summarize; explain the significance regarding themes like domestic innovation, sovereignty, international trust, or export opportunities.]
                >
                > **Read More:**
                > [The real provided hyperlink]

                STRICT PROHIBITIONS:
                1. DO NOT include any introductory filler (e.g., 'Sure, here is your newsletter') before the subject line.
                2. DO NOT use placeholders like [Your Name], [Newsletter Name], or [Date].
                3. Conclude strictly with the signature: 'Maraming salamat sa pagbabasa! - Your Automated News Bot'.

                DATA: \n" . $aggregatedNews;

                $response = $client->post('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-lite:generateContent?key=' . $apiKey, [
                    'headers' => ['Content-Type' => 'application/json'],
                    'json' => ['contents' => [[ 'parts' => [['text' => $promptText]] ]]]
                ]);

                $body = json_decode($response->getBody(), true);
                $aiDraft = $body['candidates'][0]['content']['parts'][0]['text'];
                $aiStatus = "<span class='badge success'>Drafted</span>";

                $Parsedown = new Parsedown();
                $htmlContent = $Parsedown->text($aiDraft);
                $emailPreview = $htmlContent;

                // 4. Send Email
                $mail = new PHPMailer(true);
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = $_ENV['SENDER_EMAIL'];
                $mail->Password   = $_ENV['GMAIL_APP_PASSWORD'];
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; 
                $mail->Port       = 465;
                $mail->CharSet    = 'UTF-8'; 

                $mail->setFrom($_ENV['SENDER_EMAIL'], 'PHP News Bot');
                $mail->addAddress($targetEmail);
                $mail->isHTML(true);
                $subjectTag = (count($fetchedNames) > 1) ? "Multi-Source" : $fetchedNames[0];
                $mail->Subject = "🚀 PH News Digest [$subjectTag]: " . date('M d');
                $mail->Body = "<div style='font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto;'>" . $htmlContent . "</div>";

                $mail->send();
                $emailStatus = "<span class='badge success'>Sent to " . htmlspecialchars($targetEmail) . "</span>";
                
                $endTime = date('h:i:s A');
                $logMessage .= "<span style='color: #2ea043;'>✅ Pipeline completed successfully at {$endTime}.</span>";

                if (isset($_GET['automate'])) { echo "SUCCESS"; exit; }

            } else {
                throw new Exception("Could not reach any of the selected RSS feeds.");
            }

        } catch (Exception $e) {
            $rawError = $e->getMessage();
            $safeError = str_replace(isset($_ENV['GEMINI_API_KEY']) ? $_ENV['GEMINI_API_KEY'] : 'API_KEY', "********[HIDDEN]********", $rawError);
            $errorMessage = $safeError;
            $aiStatus = "<span class='badge error'>Failed</span>";
            $logMessage .= "<span style='color: #f85149;'>❌ Pipeline failed at " . date('h:i:s A') . ".</span>";
            if (isset($_GET['automate'])) { echo "ERROR"; exit; }
        }
    } else {
        $aiStatus = "<span class='badge error'>Failed (Validation)</span>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI News Engine</title>
    <style>
        :root {
            --bg-color: #0d1117;
            --card-bg: #161b22;
            --text-main: #c9d1d9;
            --text-muted: #8b949e;
            --accent: #58a6ff;
            --success: #2ea043;
            --error: #f85149;
            --border: #30363d;
        }
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; background-color: var(--bg-color); color: var(--text-main); margin: 0; padding: 40px 20px; display: flex; justify-content: center; }
        .container { max-width: 850px; width: 100%; }
        
        .header { border-bottom: 1px solid var(--border); padding-bottom: 20px; margin-bottom: 30px; display: flex; justify-content: space-between; align-items: stretch; }
        .header-left { display: flex; flex-direction: column; justify-content: center; }
        .header-left h1 { margin: 0; font-size: 28px; color: var(--accent); }
        .header-left p { margin: 5px 0 0 0; color: var(--text-muted); font-size: 16px; }
        
        .auto-btn { 
            border: 1px solid var(--border); padding: 0 25px; border-radius: 12px; font-size: 14px; font-weight: bold; 
            cursor: pointer; transition: 0.3s; color: white; display: flex; align-items: center; gap: 12px; min-height: 65px;
        }
        .auto-btn.off { background: #30363d; }
        .auto-btn.on { background: var(--success); border-color: var(--success); box-shadow: 0 0 15px rgba(46, 160, 67, 0.3); }
        .auto-btn span { font-size: 18px; }

        .card { background-color: var(--card-bg); border: 1px solid var(--border); border-radius: 8px; padding: 20px; margin-bottom: 20px; width: 100%; position: relative; }
        .status-row { display: flex; justify-content: space-between; align-items: center; padding: 15px 0; border-bottom: 1px solid var(--border); }
        .status-row:last-child { border-bottom: none; }
        
        .badge { padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 600; min-width: 100px; text-align: center; display: inline-block; color: white !important; }
        .badge.idle { background: #30363d; color: var(--text-muted) !important; }
        .badge.success { background-color: var(--success); border: 1px solid rgba(255,255,255,0.1); }
        .badge.error { background-color: var(--error); }

        .controls-container { display: flex; flex-direction: column; gap: 20px; background: #010409; padding: 20px; border-radius: 6px; border: 1px solid var(--border); width: 100%; }
        .form-row { display: flex; gap: 15px; align-items: center; flex-wrap: wrap; }
        .source-selection { display: flex; gap: 15px; flex-wrap: wrap; padding: 10px; border: 1px dashed var(--border); border-radius: 6px; background: #0d1117; }
        .source-selection label { font-size: 13px; cursor: pointer; display: flex; align-items: center; gap: 5px; }
        
        .input-field { background-color: #0d1117; color: var(--text-main); border: 1px solid var(--border); padding: 10px 14px; border-radius: 6px; font-size: 14px; outline: none; }
        .input-field:focus { border-color: var(--accent); }
        .btn { background-color: #238636; color: white; border: 1px solid rgba(240, 246, 252, 0.1); padding: 12px 24px; font-size: 14px; font-weight: 600; border-radius: 6px; cursor: pointer; transition: 0.2s; display: flex; align-items: center; gap: 8px; }
        .btn:hover { background-color: #2ea043; transform: translateY(-1px); }
        
        .log-box { background-color: #010409; font-family: 'Courier New', monospace; padding: 15px; border-radius: 6px; border: 1px solid var(--border); color: var(--text-muted); line-height: 1.4; margin-top: 10px;}
        .preview-box { background-color: #ffffff; color: #333; padding: 30px; border-radius: 6px; margin-top: 15px; border: 1px solid var(--border); }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <div class="header-left">
            <h1>⚙️ AI Newsletter Engine</h1>
            <p>Dynamic Aggregator Pipeline</p>
        </div>
        <button type="button" id="autoToggle" class="auto-btn <?php echo $statusData['active'] ? 'on' : 'off'; ?>" onclick="toggleAutomation()">
            <span>●</span> Automation: <?php echo $statusData['active'] ? 'RUNNING' : 'ASLEEP'; ?>
        </button>
    </div>

    <form method="POST" class="card">
        <div class="controls-container">
            <div class="form-row">
                <strong style="font-size: 14px; color: var(--accent);">1. Select News Sources:</strong>
                <div class="source-selection">
                    <?php foreach ($availableSources as $key => $src): ?>
                        <label>
                            <input type="checkbox" name="news_sources[]" value="<?php echo $key; ?>" checked> 
                            <?php echo $src['name']; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-row">
                <strong style="font-size: 14px; color: var(--accent);">2. Recipient & Schedule:</strong>
                <select name="send_to" id="sendTo" class="input-field" onchange="toggleEmailInput()">
                    <option value="self">Send to: Self</option>
                    <option value="others">Send to: Others...</option>
                </select>
                <input type="email" name="custom_email" id="customEmail" class="input-field" placeholder="Enter target Gmail" style="display: none;">
                
                <input type="time" id="schedTime" class="input-field" value="<?php echo $statusData['target_time']; ?>" onchange="updateScheduleTime(this.value)">
                
                <button type="submit" name="run_engine" class="btn">🚀 Run Pipeline</button>
            </div>
        </div>
    </form>

    <div class="card">
        <h3 style="margin-top: 0; margin-bottom: 5px;">System Diagnostics</h3>
        <div class="log-box" id="sysLog">> <?php echo $logMessage; ?></div>

        <div style="margin-top: 20px;">
            <div class="status-row">
                <span class="status-label">1. Source Extraction</span>
                <?php echo $rssStatus; ?>
            </div>
            <div class="status-row">
                <span class="status-label">2. LLM Processing (Gemini API)</span>
                <?php echo $aiStatus; ?>
            </div>
            <div class="status-row">
                <span class="status-label">3. Mail Server (PHPMailer)</span>
                <?php echo $emailStatus; ?>
            </div>
        </div>
    </div>

    <?php if ($errorMessage): ?>
        <div class="error-box" style="background:rgba(248,81,73,0.1); border-left:4px solid var(--error); padding:15px; margin:20px 0; color:#ff7b72; font-family:monospace;">
            <strong>Exception Caught:</strong><br><br>
            <?php echo $errorMessage; ?>
        </div>
    <?php endif; ?>

    <?php if ($emailPreview): ?>
        <div class="card">
            <h3 style="margin-top: 0; margin-bottom: 10px;">Output Preview</h3>
            <div class="preview-box"><?php echo $emailPreview; ?></div>
        </div>
    <?php endif; ?>
</div>

<script>
    let automationActive = <?php echo $statusData['active'] ? 'true' : 'false'; ?>;
    let targetTime = "<?php echo $statusData['target_time']; ?>";

    function toggleAutomation() {
        const formData = new URLSearchParams();
        formData.append('toggle_scheduler', '1');

        fetch('index.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            automationActive = data.active;
            const btn = document.getElementById('autoToggle');
            btn.className = 'auto-btn ' + (automationActive ? 'on' : 'off');
            btn.innerHTML = `<span>●</span> Automation: ${automationActive ? 'RUNNING' : 'ASLEEP'}`;
            document.getElementById('sysLog').innerHTML = `> Automation state updated: ${automationActive ? 'ACTIVE' : 'IDLE'}`;
        });
    }

    function updateScheduleTime(newTime) {
        const formData = new URLSearchParams();
        formData.append('update_time', newTime);

        fetch('index.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            targetTime = data.target_time;
            document.getElementById('sysLog').innerHTML = `> Schedule updated: Now running daily at ${targetTime}`;
        });
    }

    setInterval(() => {
        if (!automationActive) return;
        const now = new Date();
        const timeStr = now.getHours().toString().padStart(2, '0') + ":" + now.getMinutes().toString().padStart(2, '0');
        
        if (timeStr === targetTime) {
            const logBox = document.getElementById('sysLog');
            logBox.innerHTML = `> Scheduled trigger hit (${timeStr}). Running background pipeline...`;
            
            fetch('index.php?automate=1')
            .then(res => res.text())
            .then(data => {
                logBox.innerHTML = `> Automation Result: ${data} at ${new Date().toLocaleTimeString()}`;
            });
        }
    }, 60000);

    function toggleEmailInput() {
        var select = document.getElementById("sendTo");
        var input = document.getElementById("customEmail");
        input.style.display = (select.value === "others") ? "block" : "none";
        input.required = (select.value === "others");
    }
</script>

</body>
</html>