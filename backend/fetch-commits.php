<?php

if (php_sapi_name() !== "cli") {
    http_response_code(403);
    exit("Forbidden");
}

const USERNAME = "Arcanovax";
const MAX_COMMITS = 5;
const OUTPUT_FILE = "commits.json";
const USER_AGENT = "Commits-Activity-Script/1.0";

$tokenFile = __DIR__ . "/config.php";
$token = file_exists($tokenFile) ? require $tokenFile : null;

if (!$token) {
    exit("Missing token: create a backend/config.php\n");
}

function ghGet(string $url, ?string $token, string $userAgent): array {
    $headers = "User-Agent: {$userAgent}\r\nAccept: application/vnd.github+json\r\n";
    if ($token) {
        $headers .= "Authorization: Bearer $token\r\n";
    }
    $context = stream_context_create([
        "http" => [
            "header" => $headers,
            "method" => "GET",
            "ignore_errors" => true
        ]
    ]);
    $res = file_get_contents($url, false, $context);
    $status = $http_response_header[0] ?? "no response";
    return [$res === false ? null : json_decode($res, true), $status];
}

$candidates = [];
$seen = [];

[$events, $status] = ghGet("https://api.github.com/users/" . urlencode(USERNAME) . "/events/public?per_page=100", $token, USER_AGENT);
echo "Events: $status\n";

foreach (is_array($events) ? $events : [] as $event) {
    if (($event["type"] ?? "") !== "PushEvent") continue;

    $sha = $event["payload"]["head"] ?? null;
    if (!$sha || isset($seen[$sha])) continue;

    $seen[$sha] = true;
    $candidates[] = ["repo" => $event["repo"]["name"], "sha" => $sha];
    if (count($candidates) >= 10) break;
}

$q = urlencode("author:" . USERNAME);
[$search, $status] = ghGet("https://api.github.com/search/commits?q=$q&sort=author-date&order=desc&per_page=10", $token, USER_AGENT);
echo "Search: $status\n";

foreach ($search["items"] ?? [] as $it) {
    $sha = $it["sha"] ?? null;
    if (!$sha || isset($seen[$sha])) continue;

    $seen[$sha] = true;
    $candidates[] = ["repo" => $it["repository"]["full_name"], "sha" => $sha];
}

echo count($candidates) . " candidate commits\n";

$out = [];

foreach ($candidates as $item) {
    [$d, $status] = ghGet("https://api.github.com/repos/{$item['repo']}/commits/{$item['sha']}", $token, USER_AGENT);
    echo "{$item['repo']}: $status\n";

    if (is_array($d) && isset($d["sha"])) {
        $out[] = $d;
    }
}

usort($out, fn($a, $b) =>
    strcmp($b["commit"]["committer"]["date"] ?? "", $a["commit"]["committer"]["date"] ?? "")
);
$out = array_slice($out, 0, MAX_COMMITS);

if (count($out) > 0) {
    file_put_contents(
        __DIR__ . "/" . OUTPUT_FILE,
        json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

echo count($out) . " commits saved\n";

