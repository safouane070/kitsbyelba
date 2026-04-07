<?php
/**
 * Shared kit-folder helpers: scan, league/club inference, product meta, paths.
 * Used by sync_kits_from_folder.php and reorganize_kits_folders.php.
 */

/** @return list<string> */
function kits_listImageFiles(string $dir, array $imgExt): array
{
    $out = [];
    if (!is_dir($dir)) {
        return $out;
    }
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        $p = $dir . DIRECTORY_SEPARATOR . $f;
        if (!is_file($p)) {
            continue;
        }
        $low = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (isset($imgExt[$low])) {
            $out[] = $f;
        }
    }
    natsort($out);
    return array_values($out);
}

/** @return list<string> */
function kits_listSubdirs(string $dir): array
{
    $out = [];
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        $p = $dir . DIRECTORY_SEPARATOR . $f;
        if (is_dir($p)) {
            $out[] = $p;
        }
    }
    return $out;
}

function kits_normText(string $s): string
{
    $s = str_replace(['_', '-'], ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return strtolower(trim($s));
}

/**
 * Leaf dirs: directory contains images and no subdirectory yields a product.
 *
 * @return bool Whether this subtree produced a product folder
 */
function kits_collectLeafProductDirs(string $dir, array $imgExt, callable $onLeaf): bool
{
    $subs = kits_listSubdirs($dir);
    $imgs = kits_listImageFiles($dir, $imgExt);
    $childProduced = false;
    foreach ($subs as $sub) {
        if (kits_collectLeafProductDirs($sub, $imgExt, $onLeaf)) {
            $childProduced = true;
        }
    }
    if (!$childProduced && count($imgs) > 0) {
        $onLeaf($dir, $imgs);
        return true;
    }
    return $childProduced || count($imgs) > 0;
}

/** Official folder names under kits/ (match shop leagues + utility buckets). */
function kits_leagueFolderNames(): array
{
    return [
        'Premier League', 'La Liga', 'Bundesliga', 'Serie A', 'Ligue 1',
        'Eredivisie', 'National Teams', '_Unsorted',
    ];
}

function kits_isKnownLeagueSegment(string $segment): bool
{
    foreach (kits_leagueFolderNames() as $n) {
        if (strcasecmp($segment, $n) === 0) {
            return true;
        }
    }
    return false;
}

/** @return list<array{cat:string,league:string,keys:list<string>}> */
function kits_clubKeywordRules(): array
{
    $prem = 'premier';
    $pl = 'Premier League';
    $ll = 'laliga';
    $la = 'La Liga';
    $bu = 'bundesliga';
    $bL = 'Bundesliga';
    $sa = 'seriea';
    $sA = 'Serie A';
    $l1 = 'ligue1';
    $L1 = 'Ligue 1';
    $er = 'eredivisie';
    $eL = 'Eredivisie';

    return [
        ['cat' => $prem, 'league' => $pl, 'keys' => [
            ' ars ', 'ars ', 'ars_', '_ars_', 'manchester united', 'man united', 'man utd', 'man_united',
            ' man u ', 'm utd', 'nottingham forest', 'tottenham', 'newcastle', 'aston villa', 'west ham',
            'crystal palace', 'brighton', 'brentford', 'fulham', 'wolves', ' wolverhampton', 'everton',
            'afc bournemouth', 'bournemouth', 'ipswich', 'leicester', 'southampton', 'leeds',
            'sheffield united', 'burnley', 'luton town', 'luton_', ' norwich ', 'watford', ' cardiff ',
            'west brom', ' stoke ', 'blackburn', 'bolton', ' sunderland ', 'middlesbrough', 'coventry',
            'bristol city', 'millwall', 'preston', 'swansea', 'huddersfield', 'rotherham', 'wigan',
            'birmingham', 'derby county', 'plymouth', 'oxford', 'charlton', 'lincoln', 'wycombe',
            'shrewsbury', 'fleetwood', 'peterborough', 'barnsley', 'burton', 'carlisle', 'cheltenham',
            'colchester', 'crawley', 'crewe', 'doncaster', 'exeter', 'grimsby', 'stockport', 'wrexham',
            'mansfield', 'tranmere', 'salford', 'sutton', 'harrogate', 'bristol rovers', 'reading fc',
            'blackpool', 'hull city', 'qpr', 'arsenal', ' liverpool ', ' chelsea ', 'man city',
            'manchester city', 'man_city',
        ]],
        ['cat' => $ll, 'league' => $la, 'keys' => [
            'real madrid', ' barcelona ', 'barca', ' fcb ', ' rm ', ' atm ', 'atletico madrid',
            ' atletico ', 'atlético', 'sevilla', ' valencia ', 'athletic bilbao', 'athletic club',
            'villarreal', 'real betis', ' betis ', 'real sociedad', ' getafe ', 'osasuna',
            'rayo vallecano', 'mallorca', 'las palmas', 'celta', ' girona ', 'gerona', 'alaves',
            ' alavés', 'espanyol', 'leganes', 'elche', ' granada ', ' cadiz ', 'cádiz', ' eibar ',
            'levante', 'deportivo', ' la coruna ', 'racing santander', ' almeria ', 'almería',
            ' real oviedo ',
        ]],
        ['cat' => $bu, 'league' => $bL, 'keys' => [
            'bayern', 'borussia dortmund', ' bvb ', 'dortmund', 'leverkusen', 'eintracht frankfurt',
            'frankfurt', 'gladbach', ' m gladbach', 'wolfsburg', 'union berlin', 'freiburg',
            'hoffenheim', 'heidenheim', 'augsburg', 'mainz', ' werder ', 'stuttgart', ' hertha ',
            ' bochum ', 'schalke', ' koln ', 'köln', 'kaiserslautern', ' hamburg ', 'hannover',
            'nuremberg', ' nürnberg', 'dynamo dresden', 'stpauli', 'st pauli',
        ]],
        ['cat' => $sa, 'league' => $sA, 'keys' => [
            'juventus', ' inter milan', ' inter ', 'ac milan', ' milan ', 'napoli', ' as roma', ' roma ',
            ' lazio ', 'atalanta', ' fiorentina ', 'bologna', 'torino', ' genoa ', 'monza', 'lecce',
            'sassuolo', 'udinese', 'verona', 'empoli', 'cagliari', ' parma ', 'como ', 'venezia',
            'salernitana', 'benevento', 'crotone', 'spezia', 'frosinone',
        ]],
        ['cat' => $l1, 'league' => $L1, 'keys' => [
            ' psg ', 'paris saint', 'marseille', ' olympique lyon', ' lyon ', 'monaco', ' lille ',
            ' ogc nice', ' nice ', 'rc lens', ' lens ', 'rennes', 'toulouse', 'strasbourg', ' nantes ',
            'montpellier', 'brest', 'reims', ' le havre ', 'angers', 'auxerre', 'saint etienne',
            ' fc metz ', ' metz ',
        ]],
        ['cat' => $er, 'league' => $eL, 'keys' => [
            ' ajax ', 'feyenoord', ' psv ', 'az alkmaar', 'fc utrecht', ' utrecht ', 'fc twente',
            ' twente ', 'vitesse', 'sparta rotterdam', 'go ahead', 'heracles', 'fortuna sittard',
            ' pec zwolle', 'zwolle', 'volendam', 'nac breda', 'eindhoven', ' sc heerenveen',
            'heerenveen', 'rkc waalwijk', 'excelsior', 'almere city', 'groningen',
        ]],
        ['cat' => 'national', 'league' => 'National Teams', 'keys' => [
            'netherlands', 'holland', 'oranje', ' england ', 'spain nt', ' germany ', ' deutschland',
            ' france ', ' italy ', 'azzurri', ' portugal ', 'seleção', ' brazil ', 'brasileir',
            ' argentina ', ' belgium ', ' croatia ', ' morocco ', ' egypt ', ' usa ', 'united states',
            ' mexico ', ' japan ', ' korea ', 'uruguay', 'colombia', 'poland', 'austria', 'switzerland',
            'turkey', 'türkiye', 'wales', 'scotland', 'ireland', 'ukraine', 'serbia', 'denmark',
            'sweden', 'norway', 'saudi', ' qatar ', 'canada', 'senegal', 'nigeria',
        ]],
    ];
}

/** @return array{cat:string,league:string}|null */
function kits_inferLeagueFromHaystack(string $hay): ?array
{
    $h = kits_normText($hay);

    if (preg_match('/national[_\s]?teams?\b/', $h) || preg_match('/\bnational\b.*\bteam/', $h)) {
        return ['cat' => 'national', 'league' => 'National Teams'];
    }

    $segments = [
        ['premier', 'Premier League', ['english premier', 'premier league', ' epl ', ' epl', 'premier ', 'premier_']],
        ['laliga', 'La Liga', ['la liga', 'laliga', 'spanish la']],
        ['bundesliga', 'Bundesliga', ['bundesliga', ' german bundesliga']],
        ['seriea', 'Serie A', ['serie a', 'seriea', 'italian serie']],
        ['ligue1', 'Ligue 1', ['ligue 1', 'ligue1', 'french ligue']],
        ['eredivisie', 'Eredivisie', ['eredivisie', 'dutch eredivisie']],
        ['national', 'National Teams', ['national team', ' international ', '_nt_', ' world cup']],
    ];

    foreach ($segments as $row) {
        [$cat, $label, $needles] = $row;
        foreach ($needles as $n) {
            if (str_contains($h, trim($n))) {
                return ['cat' => $cat, 'league' => $label];
            }
        }
    }

    foreach (kits_clubKeywordRules() as $rule) {
        foreach ($rule['keys'] as $key) {
            if (str_contains($h, $key)) {
                return ['cat' => $rule['cat'], 'league' => $rule['league']];
            }
        }
    }

    return null;
}

/**
 * Longest keyword match wins (better club names).
 *
 * @return array{cat:string,league:string,club:string}|null
 */
function kits_inferLeagueClubFromHaystack(string $hay): ?array
{
    $h = kits_normText($hay);
    $pairs = [];
    foreach (kits_clubKeywordRules() as $rule) {
        foreach ($rule['keys'] as $key) {
            $pairs[] = [$rule['cat'], $rule['league'], $key];
        }
    }
    usort($pairs, fn ($a, $b) => strlen($b[2]) <=> strlen($a[2]));
    foreach ($pairs as [$cat, $league, $key]) {
        if (str_contains($h, $key)) {
            return [
                'cat' => $cat,
                'league' => $league,
                'club' => kits_clubFolderFromKey($key),
            ];
        }
    }
    $lg = kits_inferLeagueFromHaystack($hay);
    if ($lg) {
        return [
            'cat' => $lg['cat'],
            'league' => $lg['league'],
            'club' => 'Other',
        ];
    }
    return null;
}

function kits_clubFolderFromKey(string $matchedKey): string
{
    $t = trim(strtolower(preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', $matchedKey))));
    static $map = [
        'ars' => 'Arsenal',
        'ars ' => 'Arsenal',
        'rm' => 'Real Madrid',
        'fcb' => 'Barcelona',
        'atm' => 'Atletico Madrid',
        'bvb' => 'Borussia Dortmund',
        'man utd' => 'Manchester United',
        'man united' => 'Manchester United',
        'man city' => 'Manchester City',
        'man u' => 'Manchester United',
        'm utd' => 'Manchester United',
        'inter' => 'Inter Milan',
        'ac milan' => 'AC Milan',
        'inter milan' => 'Inter Milan',
        'psg' => 'Paris Saint Germain',
        'fcb ' => 'Barcelona',
    ];
    if (isset($map[$t])) {
        return kits_safeFolderName($map[$t]);
    }
    $tc = mb_convert_case($t, MB_CASE_TITLE, 'UTF-8');
    return kits_safeFolderName($tc);
}

function kits_safeFolderName(string $name): string
{
    $name = str_replace(['\\', '/', ':', '*', '?', '"', '<', '>', '|'], '-', $name);
    $name = trim($name);
    if ($name === '' || $name === '.' || $name === '..') {
        return 'Item';
    }
    return $name;
}

/**
 * @return array{type:string,title:string,version:string,price:float}
 */
function kits_inferProductMeta(string $folderName, string $fullHaystack): array
{
    $h = kits_normText($fullHaystack);

    $version = (str_contains($h, 'player version') || str_contains($h, 'player_version') || preg_match('/\bplayer\b/', $h))
        ? 'player' : 'fan';

    $isKids = str_contains($h, 'kids') || str_contains($h, 'junior');

    $isTraining = str_contains($h, 'training') || str_contains($h, ' tracksuit') || str_contains($h, 'track suit')
        || str_contains($h, 'sweatshirt') || str_contains($h, ' windbreak') || str_contains($h, ' drill ')
        || (str_contains($h, 'anthem') && str_contains($h, 'jacket'));

    $isSet = str_contains($h, 'uniform') || str_contains($h, 'full kit') || str_contains($h, 'kit set')
        || str_contains($h, 'soccer suit') || (str_contains($h, 'soccer') && str_contains($h, 'suit'))
        || preg_match('/\bset\b/', $h);

    $type = 'shirts';
    $price = 34.99;
    if ($isKids) {
        $type = 'kids';
        $price = 29.99;
    } elseif ($isTraining) {
        $type = 'shirts';
        $price = 32.99;
    } elseif ($isSet) {
        $type = 'sets';
        $price = 39.99;
    } elseif (str_contains($h, 'jersey') || str_contains($h, 'shirt') || str_contains($h, 'long sleeve')) {
        $type = 'shirts';
        $price = 34.99;
    }

    $title = kits_prettifyFolderTitle($folderName);

    return ['type' => $type, 'title' => $title, 'version' => $version, 'price' => $price];
}

function kits_prettifyFolderTitle(string $folderName): string
{
    $t = str_replace('_', ' ', $folderName);
    $t = preg_replace('/\s*PLAYER\s*VERSION\s*$/i', '', $t);
    $t = preg_replace('/\s*FAN\s*VERSION\s*$/i', '', $t);
    $t = preg_replace('/\bS-\d+X?L\b.*$/i', '', $t);
    $t = preg_replace('/\bS-\d+[-–]\d*X*L\b.*$/i', '', $t);
    $t = preg_replace('/\s+-\s*[A-Za-z0-9]{2,12}\s*$/', '', $t);
    return trim(preg_replace('/\s+/', ' ', $t));
}

function kits_buildDisplayName(string $title, string $type, string $version, string $season): string
{
    $typeBit = [
        'shirts' => 'Shirt',
        'sets' => 'Full kit set',
        'kids' => 'Kids kit',
    ][$type] ?? 'Shirt';
    $verBit = $version === 'player' ? 'Player version' : 'Fan version';
    return $title . ' · ' . $typeBit . ' · ' . $verBit . ' · ' . $season;
}

function kits_buildDescription(string $title, string $leagueLabel, string $verBit): string
{
    return 'Premium football kit inspired by ' . $title . ' (' . $leagueLabel . '). '
        . $verBit . '. Breathable performance fabric; comfortable fit. '
        . 'Machine washable at 30°C.';
}

function kits_relPathFromRoot(string $absDir, string $kitsRoot): string
{
    $root = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $kitsRoot), DIRECTORY_SEPARATOR);
    $abs = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $absDir);
    if (str_starts_with($abs, $root)) {
        $rel = substr($abs, strlen($root));
    } else {
        $rel = '';
    }
    $rel = trim(str_replace('\\', '/', $rel), '/');
    return $rel;
}

function kits_webPath(string $relFromKitsRoot, string $file): string
{
    $rel = trim(str_replace('\\', '/', $relFromKitsRoot), '/');
    $base = $rel === '' ? 'kits' : 'kits/' . $rel;
    return $base . '/' . str_replace('\\', '/', $file);
}

function kits_pathKeyFromRel(string $rel): string
{
    $rel = trim(str_replace('\\', '/', $rel), '/');
    return $rel === '' ? 'kits' : ('kits/' . $rel);
}
