<?php
declare(strict_types=1);

use voku\helper\HtmlDomParser;

require 'vendor/autoload.php';

// 感谢 https://foosoft.net/projects/anki-connect/
// 感谢 https://ankiweb.net/shared/info/1284759083

// 授权码，https://my.eudic.net/OpenAPI/Authorization
$auth = '';
$auth_file = __DIR__.'/auth.txt';
if (is_file($auth_file) && is_readable($auth_file)) {
    $auth = (string) file_get_contents($auth_file);
}

// 生词本ID https://my.eudic.net/studyList
const BOOK_ID = 0;
// 牌组名称
const DECK_NAME = '生词收集';

const PAGE_SIZE = 100;

const MODEL_NAME = 'Word2Anki';

const MODEL_FIELDS = ['term', 'image', 'definition', 'sentenceFront', 'sentenceBack', 'phraseFront', 'phraseBack', 'BrEPhonetic', 'AmEPhonetic', 'BrEPron', 'AmEPron'];  // 名称不可修改

$flag = 0;
$index = 0;
$flag_file = __DIR__.'/offset.txt'; // 存放从词典取生词的标识
if (is_file($flag_file) && is_readable($flag_file)) {
    $flag = (int) file_get_contents($flag_file);
}

$start = floor($flag / PAGE_SIZE);
$offset = $flag % PAGE_SIZE;

deckNames();
modelFieldNames();

while (true) {
    $list = study($auth, $start);
    $data = json_decode($list, true);
    if (empty($data['data'])) {
        die('Empty');
    }
    $fields = MODEL_FIELDS;
    unset($fields['sentenceFront'], $fields['sentenceBack'], $fields['phraseFront'], $fields['phraseBack']);
    $fields[] = 'sentence';
    $fields[] = 'phrase';
    $index = $start * PAGE_SIZE;
    foreach ($data['data'] as $id => $datum) {
        ++$index;
        $ret0 = findNotes('deck:'.DECK_NAME.' term:'.$datum['word']);
        $ret1 = findNotes('word:'.$datum['word']);
        if (!empty($ret0) || !empty($ret1)) {
            echo $index.": ".$datum['word']." exist.\n";
            continue;
        }
        $em = 0;
        $pa = peu(...eu($datum['word']));
        foreach ($fields as $field) {
            if (empty($pa[$field])) {
                ++$em;
            }
        }
        if ($em > 1) {
            $pb = pyd(...yd($datum['word']));
            foreach ($fields as $field) {
                if (empty($pa[$field])) {
                    $pa[$field] = !empty($pb[$field]) ? $pb[$field] : '';
                }
            }
        }
        if (!addNote($pa)) {
            echo $datum['word']." Fail\n";
            continue;
        }
        echo $index.": ".$datum['word']." OK.\n";
    }
    if (is_writable($flag_file)) {
        file_put_contents($flag_file, $index);
    }
    if (count($data['data']) < PAGE_SIZE) {
        break;
    }
    ++$start;
}

if ($index > $flag) {
    sync();
}

function study($auth, $page = 1)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.frdic.com/api/open/v1/studylist/words/'.BOOK_ID.'?language=en&page_size='.PAGE_SIZE.'&page='.$page);
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: '.$auth,
    ]);
    $response = curl_exec($ch);
    if (!$response || 200 != curl_getinfo($ch, CURLINFO_HTTP_CODE)) {
        die('Error: "'.curl_error($ch).'" - Code: '.curl_errno($ch))."\n";
    }
    curl_close($ch);

    return $response;
}

// 查询有道词典
function yd($word): array
{
    $j = '{"dicts": {"count": 99, "dicts": [["ec", "ee", "phrs", "pic_dict"], ["web_trans"], ["fanyi"], ["blng_sents_part"]]}}';
    $j = json_decode($j, true);
    $j['q'] = $word;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://dict.youdao.com/jsonapi?'.http_build_query($j));
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_13_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/69.0.3497.100 Safari/537.36',
    ]);
    $response = curl_exec($ch);
    if (!$response) {
        die('Error: "'.curl_error($ch).'" - Code: '.curl_errno($ch))."\n";
    }
    curl_close($ch);

    return [$word, $response];
}

// 解析有道词典查询结果
function pyd($word, $resp): array
{
    $data = json_decode($resp, true);
    if (!$data) {
        return [];
    }
    $ec = [];
    if (!empty($data['ec']['word'][0]['trs'])) {
        foreach ($data['ec']['word'][0]['trs'] as $pair) {
            $ec[] = $pair['tr'][0]['l']['i'][0];
        }
    }
    $ee = [];
    if (!empty($data['ee']['word']['trs'])) {
        foreach ($data['ee']['word']['trs'] as $pair) {
            $ee[] = $pair['pos'].' '.$pair['tr'][0]['l']['i'];
        }
    }
    $exp = array_merge($ec, $ee);
    $web_trans = [];
    if (!empty($data['web_trans']['web-translation'][0]['trans'])) {
        foreach ($data['web_trans']['web-translation'][0]['trans'] as $tran) {
            $web_trans[] = $tran['value'];
        }
    }
    if (empty($exp)) {
        $exp = $web_trans;
    }
    $audio_url = 'http://dict.youdao.com/dictvoice?audio=';
    // 英式音标
    $pron['AmEPhonetic'] = '';
    if (!empty($data['simple']['word'][0]['usphone'])) {
        $pron['AmEPhonetic'] = $data['simple']['word'][0]['usphone'];
    }
    // 美式音标
    $pron['BrEPhonetic'] = '';
    if (!empty($data['simple']['word'][0]['ukphone'])) {
        $pron['BrEPhonetic'] = $data['simple']['word'][0]['ukphone'];
    }
    // 美式发音url
    $pron['AmEUrl'] = '';
    if (!empty($data['simple']['word'][0]['usspeech'])) {
        $pron['AmEUrl'] = $audio_url.$data['simple']['word'][0]['usspeech'];
    }
    // 英式发音url
    $pron['BrEUrl'] = '';
    if (!empty($data['simple']['word'][0]['ukspeech'])) {
        $pron['BrEUrl'] = $audio_url.$data['simple']['word'][0]['ukspeech'];
    }
    $sentence = [];
    if (!empty($data['blng_sents_part']['sentence-pair'])) {
        foreach ($data['blng_sents_part']['sentence-pair'] as $pair) {
            $sentence[] = [$pair['sentence-eng'], $pair['sentence-translation']];
        }
    }
    $image = '';
    if (!empty($data['pic_dict']['pic'][0]['image'])) {
        $image = $data['pic_dict']['pic'][0]['image'];
    }
    $phrase = [];
    if (!empty($data['phrs']['phrs'])) {
        foreach ($data['phrs']['phrs'] as $pair) {
            if (!empty($pair['phr']['headword']['l']['i']) && !empty($pair['phr']['trs'][0]['tr']['l']['i'])) {
                $phrase[] = [$pair['phr']['headword']['l']['i'], $pair['phr']['trs'][0]['tr']['l']['i']];
            }
        }
    }

    return [
        'term' => $word, 'definition' => $exp, 'phrase' => $phrase, 'image' => $image, 'sentence' => $sentence, 'BrEPhonetic' => $pron['BrEPhonetic'],
        'AmEPhonetic' => $pron['AmEPhonetic'], 'BrEPron' => $pron['BrEUrl'], 'AmEPron' => $pron['AmEUrl'],
    ];
}

// 查询欧路词典
function eu($word): array
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://dict.eudic.net/dicts/en/'.rawurlencode($word));
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_13_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/69.0.3497.100 Safari/537.36',
    ]);
    $response = curl_exec($ch);
    if (!$response) {
        echo 'Error: "'.curl_error($ch).'" - Code: '.curl_errno($ch)."\n";

        return [$word, ''];
    }
    curl_close($ch);

    return [$word, $response];
}

// 解析欧陆词典查询结果
function peu($word, $resp): array
{
    if (empty($resp)) {
        return ['term' => $word];
    }
    $dom = HtmlDomParser::str_get_html($resp);
    $div = $dom->findOne('#ExpFCChild');
    if (empty($div)) {
        return ['term' => $word];
    }
    $exp = [];
    $li = $div->findMulti('li');
    if (count($li) > 0) {
        foreach ($li as $item) {
            $exp[] = $item->plaintext;
        }
    } else {
        $l = $div->findOne('.exp');
        if (count($li) > 0) {
            $exp[] = $l->plaintext;
        } else {
            $tr = $div->findOne('#trans');
            $in = $div->outerHtml();
            if (!empty($tr)) {
                $in = str_replace($tr->outerHtml(), '', $in);
            }
            foreach ($div->findMulti('a') as $childNode) {
                $in = str_replace($childNode->outerHtml(), '', $in);
            }
            foreach ($div->findMulti('script') as $childNode) {
                $in = str_replace($childNode->outerHtml(), '', $in);
            }
            $e = trim(strip_tags($in));
            if (!empty($e)) {
                $exp[] = $e;
            }
            $e = ajaxtrans($word);
            if (!empty($e)) {
                $exp[] = $e;
            }
        }
    }

    $audio_url = 'https://api.frdic.com/api/v2/speech/speakweb?';
    $div = $dom->findOne('.phonitic-line');
    $links = $div->findMultiOrFalse('a');
    $phons = $div->findMultiOrFalse('.Phonitic');
    if (!$links) {
        $link = $div->findOne('div .gv_details .voice-button');
        $links = [$link, $link]; // 没错返回相同的
    }
    // 英式音标
    $pron['BrEPhonetic'] = '';
    // 英式发音url
    $pron['BrEUrl'] = '';
    // 美式音标
    $pron['AmEPhonetic'] = '';
    // 美式发音url
    $pron['AmEUrl'] = '';
    if (!empty($phons)) {
        $pron['BrEPhonetic'] = trim($phons[0]->plaintext);
        if (!empty($links[0]) && !empty($links[0]->getAttribute('data-rel'))) {
            if (0 !== strpos($links[0]->getAttribute('data-rel'), 'http')) {
                $pron['BrEUrl'] = $audio_url.$links[0]->getAttribute('data-rel');
            } else {
                $pron['BrEUrl'] = $links[0]->getAttribute('data-rel');
            }
        }
        $pron['AmEPhonetic'] = trim(empty($phons[1]) ? $phons[0]->plaintext : $phons[1]->plaintext);
        if (!empty($links[1]) && !empty($links[1]->getAttribute('data-rel'))) {
            if (0 !== strpos($links[1]->getAttribute('data-rel'), 'http')) {
                $pron['AmEUrl'] = $audio_url.$links[1]->getAttribute('data-rel');
            } else {
                $pron['AmEUrl'] = $links[1]->getAttribute('data-rel');
            }
        }
    }

    $div = $dom->findMulti('div #ExpLJChild .lj_item');
    $sentences = [];
    foreach ($div as $item) {
        $line = $item->findOne('p.line');
        if (!empty($line)) {
            $sentence = trim($line->innerHtml(), '"');
            $p = $item->findOne('p.exp');
            if (!empty($p)) {
                $translation = $p->plaintext;
                $sentences[] = [$sentence, $translation];
            }
        }
    }

    $div = $dom->findOne('div .word-thumbnail-container img');
    $image = '';
    if (empty($div->getAttribute('title'))) {
        $image = $div->getAttribute('src');
        if (!empty($image) && 0 !== strpos($image, 'http')) {
            $image = 'https:'.$image;
        }
    }

    $div = $dom->findMulti('div #ExpSPECChild #phrase');
    $phrase = [];
    foreach ($div as $item) {
        $i = $item->findOne('i');
        $o = $item->findOne('.exp');
        if (!empty($i) && !empty($o)) {
            $phrase[] = [$i->plaintext, $o->plaintext];
        }
    }

    return [
        'term' => $word, 'definition' => $exp, 'phrase' => $phrase, 'image' => $image, 'sentence' => $sentences, 'BrEPhonetic' => $pron['BrEPhonetic'],
        'AmEPhonetic' => $pron['AmEPhonetic'], 'BrEPron' => $pron['BrEUrl'], 'AmEPron' => $pron['AmEUrl'],
    ];
}

function down_file($url, $file): bool
{
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    $fp = fopen($file, 'w+');
    curl_setopt($curl, CURLOPT_FILE, $fp);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($curl, CURLOPT_TIMEOUT, 50);

    curl_exec($curl);

    $return = 200 == curl_getinfo($curl, CURLINFO_HTTP_CODE);

    fclose($fp);
    curl_close($curl);

    return $return;
}

function file_ext($file): string
{
    $finfo = finfo_open(FILEINFO_EXTENSION); // gib den MIME-Typ nach Art der mimetype Extension zurück
    $extensions = finfo_file($finfo, $file);
    finfo_close($finfo);
    if (!$extensions) {
        return '';
    }
    $extensions = explode('/', $extensions);

    return $extensions[0];
}

// Card Actions

function suspend($query): bool
{
    $json = '{"action":"suspend","version":6,"params":{"cards":['.$query.']}}';
    $notes = posturl($json);

    return $notes['result'];
}

function suspended($query): bool
{
    $json = '{"action":"suspended","version":6,"params":{"card":'.$query.'}}';
    $notes = posturl($json);

    return $notes['result'];
}

// Note Actions

function addNote($dict): bool
{
    if (empty($dict)) {
        return false;
    }
    if (false === strpos($dict['BrEPhonetic'], '/') && !empty($dict['BrEPhonetic'])) {
        $dict['BrEPhonetic'] = "/{$dict['BrEPhonetic']}/";
    }
    if (false === strpos($dict['AmEPhonetic'], '/') && !empty($dict['AmEPhonetic'])) {
        $dict['AmEPhonetic'] = "/{$dict['AmEPhonetic']}/";
    }
    $even = ' class="even"';
    $odd = ' class="odd"';
    $definition = '';
    foreach ($dict['definition'] as $idx => $item) {
        $s = 1 == $idx % 2 ? $odd : $even;
        $definition .= '<div'.$s.'>'.$item.'</div>';
    }
    $dict['definition'] = $definition;
    foreach (['phrase', 'sentence'] as $value) {
        $dict["{$value}Front"] = '';
        $dict["{$value}Back"] = '';
        if (!empty($dict["{$value}"])) {
            foreach ($dict["{$value}"] as $idx => $item) {
                $s = 1 == $idx % 2 ? $odd : $even;
                $dict["{$value}Front"] .= '<div'.$s.'>'.$item[0].'</div>';
                $dict["{$value}Back"] .= '<div'.$even.'>'.$item[0].'</div><div'.$odd.'>'.$item[1].'</div>';
            }
        }
        unset($dict["{$value}"]);
    }
    $image = $dict['image'];
    if (!empty($image)) {
        $dict['image'] = '<img src="'.$dict['term'].'.png'.'" alt="" onerror="this.style.display=\'none\'" style="max-height:120px">';
    }
    $BrEPron = htmlspecialchars_decode($dict['BrEPron']);
    $AmEPron = htmlspecialchars_decode($dict['AmEPron']);
    unset($dict['BrEPron'], $dict['AmEPron']);
    $note['action'] = 'addNote';
    $note['version'] = 6;
    $note['params'] = [
        'note' => [
            'deckName' => DECK_NAME, 'modelName' => MODEL_NAME.'-'.BOOK_ID, 'fields' => $dict, 'audio' => [],
        ],
    ];
    if (!empty($BrEPron)) {
        $note['params']['note']['audio'][] = [
            'url' => $BrEPron, 'filename' => "BrEPron_{$dict['term']}.mp3", 'fields' => [
                'BrEPron',
            ],
        ];
    }
    if (!empty($AmEPron)) {
        $note['params']['note']['audio'][] = [
            'url' => $AmEPron, 'filename' => "AmEPron_{$dict['term']}.mp3", 'fields' => [
                'AmEPron',
            ],
        ];
    }
    if (empty($BrEPron) && empty($AmEPron)) {
        unset($note['params']['note']['audio']);
    }
    if (empty($image)) {
        $data = posturl($note);

        return !empty($data['result']) || 'cannot create note because it is a duplicate' == $data['error'];
    }
    $local_file = uniqid('', true);
    $ret = down_file($image, $local_file);
    if (!$ret) {
        $params = [
            'filename' => $dict['term'].'.png', 'url' => $image,
        ];
        echo $dict['term'], ' ', $image, "\n";
    } else {
        $ext = file_ext($local_file);
        $content = file_get_contents($local_file);
        if (empty($ext) || !$content) {
            $params = [
                'filename' => $dict['term'].'.png', 'url' => $image,
            ];
            echo $dict['term'], ' ', $image, "\n";
        } else {
            $params = [
                'filename' => $dict['term'].'.'.$ext, 'data' => base64_encode($content),
            ];
            $note['params']['note']['fields']['image'] = '<img src="'.$params['filename'].'" alt="" onerror="this.style.display=\'none\'" style="max-height:120px">';
        }
    }
    unlink($local_file);
    $multi['action'] = 'multi';
    $multi['params'] = [
        'actions' => [
            ['action' => 'storeMediaFile', 'params' => $params], $note,
        ],
    ];
    $data = posturl($multi);

    return !empty($data[1]['result'] || 'cannot create note because it is a duplicate' == $data[1]['error']);
}

function findNotes($query): array
{
    $json = '{"action":"findNotes","version":6,"params":{"query":"'.$query.'"}}';
    $notes = posturl($json);

    return $notes['result'];
}

function notesInfo($query): array
{
    $json = '{"action":"notesInfo","version":6,"params":{"notes":['.$query.']}}';
    $notes = posturl($json);

    return $notes['result'];
}

function deleteNotes($query)
{
    $json = '{"action":"deleteNotes","version":6,"params":{"notes":['.$query.']}}';
    $notes = posturl($json);
}

// Model Actions

function modelFieldNames()
{
    $json = '{"action":"modelFieldNames","version":6,"params":{"modelName":"'.MODEL_NAME.'-'.BOOK_ID.'"}}';
    $fields = posturl($json);
    if (empty($fields['result']) || !empty(array_diff(MODEL_FIELDS, $fields['result']))) {
        createModel();
    }
}

function createModel()
{
    $data['action'] = 'createModel';
    $data['version'] = 6;
    $data['params'] = [
        'modelName' => MODEL_NAME.'-'.BOOK_ID, 'inOrderFields' => MODEL_FIELDS, 'cardTemplates' => [
            [
                'Name' => 'Card 1', 'Front' => <<<end
<h1 class="term">{{term}}</h1>
<hr>
<div class="phons">🇬🇧 <span class="phonetic">{{BrEPhonetic}}</span>  🇺🇸 <span class="phonetic">{{AmEPhonetic}}</span></div>
<div style="text-align:center;">{{image}}</div>
<hr>
短语：
<div>{{phraseFront}}</div>
<hr>
例句：
<div>{{sentenceFront}}</div>
<hr>
<div style="text-align:center;">
{{BrEPron}}
<div style="display:none;">[sound:_1sec.mp3]</div>
{{AmEPron}}
</div>
end, 'Back' => <<<end
<h1 class="term">{{term}}</h1>
<hr>
<div class="phons">🇬🇧 <span class="phonetic">{{BrEPhonetic}}</span>  🇺🇸 <span class="phonetic">{{AmEPhonetic}}</span></div>
<div style="text-align:center;">{{image}}</div>
<hr>
释义：
<div>{{definition}}</div>
<hr>
短语：
<div>{{phraseBack}}</div>
<hr>
例句：
<div>{{sentenceBack}}</div>
end,
            ],
        ], 'css' => <<<end
@font-face {
    font-family: "Charis SIL";
    src: url('_CharisSIL-R.ttf');
}
@font-face {
    font-family: "Sans Forgetica";
    src: url('_SansForgetica-Regular.otf');
}
h1, .phons {
    text-align: center;
}
h1 {
    font-family: "Sans Forgetica", Arial, serif;
}
.card {
    font-family: arial, serif;
    text-align: left;
    color: black;
    background-color: white;
}
.phonetic {
    font-family: "Charis SIL", "Times New Roman", serif;
    color: #666;
}
.term {
    font-size: 35px;
}
.even, .odd {
    padding: 4px;
    margin-left: 14px;
}
.even {
    border: 1px solid #eee;
}
.odd {
    background-color: #eee;
}
end,
    ];
    posturl($data);
}

// Deck Actions

function deckNames()
{
    $json = '{"action": "deckNames","version": 6}';
    $decks = posturl($json);
    if (empty($decks['result']) || false === array_search(DECK_NAME, $decks['result'])) {
        createDeck();
    }
}

function createDeck()
{
    $json = '{"action":"createDeck","version":6,"params":{"deck":"'.DECK_NAME.'"}}';
    posturl($json);
}

function sync()
{
    $json = '{"action":"sync","version":6}';
    posturl($json);
}

function posturl($data)
{
    if (is_array($data)) {
        $data = json_encode($data);
    }
    // echo $data, "\n";
    $headerArray = ["Content-type:application/json;charset='utf-8'", 'Accept:application/json'];
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, 'http://127.0.0.1:8765');
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headerArray);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    $output = curl_exec($curl);
    curl_close($curl);
    // echo $output, "\n";

    return json_decode($output, true);
}

function ajaxtrans($data)
{
    $data = urlencode($data);
    $data = "to=zh-CN&from=en&text=${data}&contentType=text%2Fplain";
    // echo $data, "\n";
    $headerArray = ["Content-type:application/x-www-form-urlencoded; charset='UTF-8'", 'Accept:*/*'];
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, 'https://dict.eudic.net/Home/TranslationAjax');
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headerArray);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    $output = curl_exec($curl);
    curl_close($curl);
    // echo $output, "\n";

    return $output;
}
