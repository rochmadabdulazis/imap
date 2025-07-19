<?php
function imapOpen($mailbox)
{
    global $imapUser, $imapPasswd;
    $GLOBALS['imap'] = $imap = imap_open($mailbox, $imapUser, $imapPasswd);
    if (!$imap) die(imap_last_error());
}
function imapClose()
{
    if (isset($GLOBALS['imap'])) imap_close($GLOBALS['imap']);
}
function imapGetFolder()
{
    global $imap, $imapServer;
    $return = [];
    $folders = imap_list($imap, $imapServer, "*");
    foreach ($folders as $folder) {
        $return[] = ['name' => str_replace($imapServer, "", imap_utf7_decode($folder)),'mailbox' => imap_utf7_decode($folder), 'status' => imap_status($imap, $folder, SA_MESSAGES | SA_UNSEEN)];
    }
    return $return;
}
function imapGetMail($page = 1, $perPage = 20)
{
    global $imap;
    $emails = imap_sort($imap, SORTARRIVAL, 1);
    if (!$emails) return false;
    $total = count($emails);
    $return['pages'] = ceil($total / $perPage);
    $page = max($page, 1);
    $offset = ($page - 1) * $perPage;
    $emailSub = array_slice($emails, $offset, $perPage);
    foreach ($emailSub as $msgno) {
        $list = 0;
        $return['email'][] = imap_fetch_overview($imap, $msgno)[0];
    }
    return $return;
    // yang diambil from, (to), subject, date, msgno, (uid), seen, udate(time)
}
function decodeHeader($str)
{
    $decode = imap_mime_header_decode($str);
    $return = '';
    foreach ($decode as $part) {
        $return .= $part->text;
    }
    return $return;
}
function imapGetHeader($msgno)
{
    global $imap;
    return imap_headerinfo($imap, $msgno);
    // diambil date, subject, to -> [personal, mailbox, host], from -> sama seperti to, reply_to -> sama, msgno, size, udate
}

function parseImapStructure($imapStream, $msgNumber) {
    $structure = imap_fetchstructure($imapStream, $msgNumber);
    $result = [
        'plain' => null,
        'html' => null,
        'inline' => [],
        'attachment' => []
    ];

    if (!$structure) return $result;

    // $stack = [[$structure, '1']];

    $stack = [];

    if (isset($structure->parts)) {
        // multipart: mulai dari root tanpa section
        foreach ($structure->parts as $i => $subPart) {
            $stack[] = [$subPart, (string)($i + 1)];
        }
    } else {
        // single-part: langsung bagian 1
        $stack[] = [$structure, '1'];
    }

    while ($stack) {
        list($part, $section) = array_pop($stack);

        // multipart tidak punya body langsung, perlu ditelusuri bagian-bagiannya
        if (isset($part->parts)) {
            foreach ($part->parts as $i => $subPart) {
                $stack[] = [$subPart, $section . '.' . ($i + 1)];
            }
            continue;
        }

        $type = $part->type;
        $subtype = strtolower($part->subtype ?? '');
        $encoding = $part->encoding ?? 0;

        $ifdis = $part->ifdisposition;
        $disposition = strtolower($part->disposition ?? '');
        $filename = null;

        // Coba ambil nama file
        if (isset($part->dparameters)) {
            foreach ($part->dparameters as $param) {
                if (strtolower($param->attribute) === 'filename') {
                    $filename = $param->value;
                }
            }
        }
        if (isset($part->parameters)) {
            foreach ($part->parameters as $param) {
                if (strtolower($param->attribute) === 'name') {
                    $filename = $param->value;
                }
            }
        }

        if ($type === 0) { // text
            if ($subtype === 'plain' && !$result['plain'] && !$ifdis) {
                $result['plain'] = ['section' => $section, 'encoding' => $encoding];
            } elseif ($subtype === 'html' && !$result['html'] && !$ifdis) {
                $result['html'] = ['section' => $section, 'encoding' => $encoding];
            }
        }

        if ($disposition === 'inline' && $filename) {
            $result['inline'][] = [
                'section' => $section,
                'encoding' => $encoding,
                'filename' => $filename
            ];
        }

        if ($disposition === 'attachment' && $filename) {
            $result['attachment'][] = [
                'section' => $section,
                'encoding' => $encoding,
                'filename' => $filename
            ];
        }
    }

    return $result;
}
function decodePart($text, $encoding)
{
    switch ($encoding) {
        case 0: return $text;
        case 1: return $text;
        case 2: return imap_binary($text);
        case 3: return base64_decode($text);
        case 4: return quoted_printable_decode($text);
        default: return $text;
    }
}

function autoLink($text)
{
    $links = [];
    $i = 0;

    // Auto-link untuk http/https
    $text = preg_replace_callback('/\b(https?:\/\/[^\s<>"\'\]\[(){}]+)/i', function($matches) use (&$links, &$i) {
        $url = rtrim($matches[1], '.,)>]}');
        $key = '[[[AUTO_LINK_' . $i++ . ']]]';
        $links[$key] = '<a href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener noreferrer">' 
                     . htmlspecialchars(substr($url, 0, 50)) . '</a>';
        return $key;
    }, $text);

    // Auto-link untuk www
    $text = preg_replace_callback('/\b(www\.[^\s<>"\'\]\[(){}]+)/i', function($matches) use (&$links, &$i) {
        $url = rtrim($matches[1], '.,)>]}');
        $key = '[[[AUTO_LINK_' . $i++ . ']]]';
        $links[$key] = '<a href="http://' . htmlspecialchars($url) . '" target="_blank" rel="noopener noreferrer">'
                     . htmlspecialchars(substr($url, 0, 50)) . '</a>';
        return $key;
    }, $text);

    // Auto-link untuk email
    $text = preg_replace_callback('/\b([A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,})\b/i', function($matches) use (&$links, &$i) {
        $email = $matches[1];
        $key = '[[[AUTO_LINK_' . $i++ . ']]]';
        $links[$key] = '<a href="mailto:' . htmlspecialchars($email) . '">' . htmlspecialchars($email) . '</a>';
        return $key;
    }, $text);

    // Escape seluruh teks
    $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    // Replace kembali placeholder dengan <a>
    $text = strtr($text, $links);

    return nl2br($text);
}

function getAttachments($msgno, $partNumber, $filename, $encoding)
{
    global $imap;
    $data = imap_fetchbody($imap, $msgno, $partNumber);

    switch ($encoding) {
        case 3: $data = base64_decode($data); break;
        case 4: $data = quoted_printable_decode($data); break;
    }

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($data));
    echo($data);
    imapClose();
    exit;
}
function getPart($msgno, $partNumber, $encoding)
{
    global $imap;
    $data = imap_fetchbody($imap, $msgno, $partNumber);
    if ($data == false) return false;
    return decodePart($data, $encoding);
}
