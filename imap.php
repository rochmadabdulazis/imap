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

function imapGetBody($msgno)
{
    global $imap;
    $structure = imap_fetchstructure($imap, $msgno);

    if (!$structure) return "No support to display";

    if (!isset($structure->parts)) {
        if ($structure->type == 0 && strtoupper($structure->subtype) == "PLAIN") {
            return decodePart(imap_fetchbody($imap, $msgno, "1"), $structure->encoding);
        } else {
            return "No support to display";
        }
    }

    $result = findBodyPart($msgno, $structure, "");

    return $result ?? "(Body Kosong)";
}
function findBodyPart($msgno, $structure, $partNumber)
{
    global $imap;
    $prefix = ($partNumber === "") ? "" : $partNumber . ".";

    foreach ($structure->parts as $index => $part) {
        $currentPart = $prefix . ($index + 1);

        if ($part->type == 0 && strtoupper($part->subtype) == "PLAIN") {
            return decodePart(imap_fetchbody($imap, $msgno, $currentPart), $part->encoding);
        } elseif ($part->type == 1 && isset($part->parts)) {
            $nested = findBodyPart($msgno, $part, $currentPart);
            if ($nested) return $nested;
        }
    }
    return false;
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
    $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $pattern_url = '/\b(https?:\/\/[^\s<>]+)/i';
    $text = preg_replace_callback($pattern_url, function($matches) {
        $url = $matches[1];
        return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $url . '</a>';
    }, $text);

    $pattern_www = '/\b(www\.[^\s<>]+)/i';
    $text = preg_replace_callback($pattern_www, function($matches) {
        $url = 'http://' . $matches[1];
        return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $matches[1] . '</a>';
    }, $text);

    $pattern_email = '/\b([A-Z0-9._%+-]+@[A-Z0-9.-]\.[A-Z]{2,})\b/i';
    $text = preg_replace_callback($pattern_email, function($matches) {
        $email = $matches[1];
        return '<a href="mailto:' . $email . '">' . $email . '</a>';
    }, $text);

    return nl2br($text);
}

function scanAttachments($msgno)
{
    global $imap;
    $structure = imap_fetchstructure($imap, $msgno);
    $attachments = [];

    if (!$structure) return $attachments;

    if (!isset($structure->parts)) return parseAttachmentPart($structure, '', $attachments);

    foreach ($structure->parts as $index => $part) {
        $partNumber = strval($index + 1);
        $attachments = parseAttachmentPart($part, $partNumber, $attachments);
    }

    return $attachments;
}

function parseAttachmentPart($part, $partNumber, $attachments)
{
    $isAttachment = false;
    $filename = '';

    if (isset($part->disposition) && strtoupper($part->disposition) == 'ATTACHMENT') {
        $isAttachment = true;
    }

    if (isset($part->dparameters)) {
        foreach ($part->dparameters as $param) {
            if (strtolower($param->attribute) == 'filename ') {
                $isAttachment = true;
                $filename = decodeMimeStr($param->value);
            }
        }
    }

    if (isset($part->parameters)) {
        foreach ($part->parameters as $param) {
            if (strtolower($param->attribute) == 'name') {
                $isAttachment = true;
                $filename = decodeMimeStr($param->value);
            }
        }
    }

    if ($isAttachment && $filename != '') {
        $attachments[] = [
            'partNumber' => $partNumber,
            'filename' => $filename,
            'encoding' => $part->encoding
        ];
    }

    if (isset($part->parts)) {
        foreach ($part->parts as $subIndex => $subPart) {
            $subPartNumber = $partNumber . '.' . ($subIndex + 1);
            $attachments = parseAttachmentPart($subPart, $subPartNumber, $attachments);
        }
    }

    return $attachments;
}
function decodeMimeStr($str)
{
    $elements = imap_mime_header_decode($str);
    $result = '';
    foreach ($elements as $element) {
        $result .= $element->text;
    }
    return $result;
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

function imapGetBodyHtml($msgno, $mode = 'multi')
{
    global $imap;
    $structure = imap_fetchstructure($imap, $msgno);

    if (!$structure) return false;

    if (!isset($structure->parts)) {
        if ($structure->type == 0) {
            $body = decodePart(imap_fetchbody($imap, $msgno, "1"), $structure->encoding);
            return (strtoupper($structure->subtype) == "PLAIN") ? nl2br($body) : $body;
        } else {
            return false;
        }
    }

    $result = findBodyPartHtml($msgno, $structure, "", $mode);

    return $result ?? "(Body Kosong)";
}
function findBodyPartHtml($msgno, $structure, $partNumber, $mode)
{
    global $imap;
    $prefix = ($partNumber === "") ? "" : $partNumber . ".";

    $plain = false;
    $html = false;

    foreach ($structure->parts as $index => $part) {
        $currentPart = $prefix . ($index + 1);

        if ($part->type == 0) {
            $subType = strtoupper($part->subtype);
            $body = decodePart(imap_fetchbody($imap, $msgno, $currentPart), $part->encoding);

            if ($subType == "PLAIN") {
                $plain = $body;
            } elseif ($subType == "HTML") {
                $html = $body;
            }
        } elseif ($part->type == 1 && isset($part->parts)) {
            $nested = findBodyPartHtml($msgno, $part, $currentPart, $mode);
            if ($nested) return $nested;
        }
    }

    if ($mode == "plain") {
        return nl2br($plain);
    } elseif ($mode == "multi") {
        return $html ?? nl2br($plain);
    }

    return false;
}
