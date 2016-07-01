<?php

require_once __DIR__.'/vendor/autoload.php';

use Symfony\Component\DomCrawler\Crawler;

date_default_timezone_set('America/Los_Angeles');

$iteration_wait_time = 30; // @todo move to arg

$last_iteration['T'] = 0;
$last_iteration['DN']['fec'] = 0;
$last_iteration['UP']['fec'] = 0;
$last_iteration['DN']['crc'] = 0;
$last_iteration['UP']['crc'] = 0;

$errors_per['DN']['crc'] = 0;
$errors_per['UP']['crc'] = 0;
$errors_per['DN']['fec'] = 0;
$errors_per['UP']['fec'] = 0;

while (true) {

    $modem_url = 'http://192.168.1.254/cgi-bin/dslstatistics.ha'; // @todo move to arg
    $html = @file_get_contents($modem_url);

    if (!$html) {
        echo "\nNo connection to DSL at ".$modem_url;
        sleep($iteration_wait_time);
        continue;
    }

    $crawler = new Crawler($html);

    $xpath_template = '//*[@id="content-sub"]/div[%s]/table/tr[%s]/td[%s]';

    $vals['ST']['line_state'] = sprintf($xpath_template, 2, 1, 2);
    $vals['ST']['broadband_connection'] = sprintf($xpath_template, 2, 2, 2);

    $vals['DN']['sync_rate'] = sprintf($xpath_template, 2, 3, 2);
    $vals['UP']['sync_rate'] = sprintf($xpath_template, 2, 4, 2);

    $vals['DN']['signal_to_noise_margin'] = sprintf($xpath_template, 10, 2, 2);
    $vals['UP']['signal_to_noise_margin'] = sprintf($xpath_template, 10, 2, 3);

    $vals['DN']['line_attenuation'] = sprintf($xpath_template, 10, 3, 2);
    $vals['UP']['line_attenuation'] = sprintf($xpath_template, 10, 3, 3);

    $vals['DN']['fec_errors'] = sprintf($xpath_template, 10, 8, 2);
    $vals['UP']['fec_errors'] = sprintf($xpath_template, 10, 8, 3);

    $vals['DN']['crc_errors'] = sprintf($xpath_template, 10, 9, 2);
    $vals['UP']['crc_errors'] = sprintf($xpath_template, 10, 9, 3);

    foreach ([
                 'ST',
                 'DN',
                 'UP'
             ] as $G) {
        foreach ($vals[$G] as &$val) {
            $val = trim($crawler->filterXPath($val)->first()->text());
        }
    }

    $seconds_since_last_iteration = 0;
    if ($last_iteration['T'] !== 0) {
        $seconds_since_last_iteration = time() - $last_iteration['T'];

        $errors_per['DN']['crc'] = get_error_rate(
            $vals['DN']['crc_errors'],
            $last_iteration['DN']['crc'],
            $seconds_since_last_iteration
        );

        $errors_per['UP']['crc'] = get_error_rate(
            $vals['UP']['crc_errors'],
            $last_iteration['UP']['crc'],
            $seconds_since_last_iteration
        );

        $errors_per['DN']['fec'] = get_error_rate(
            $vals['DN']['fec_errors'],
            $last_iteration['DN']['fec'],
            $seconds_since_last_iteration
        );

        $errors_per['UP']['fec'] = get_error_rate(
            $vals['UP']['fec_errors'],
            $last_iteration['UP']['fec'],
            $seconds_since_last_iteration
        );
    }

    $last_iteration['DN']['crc'] = $vals['DN']['crc_errors'];
    $last_iteration['UP']['crc'] = $vals['UP']['crc_errors'];

    $last_iteration['DN']['fec'] = $vals['DN']['fec_errors'];
    $last_iteration['UP']['fec'] = $vals['UP']['fec_errors'];

    $last_iteration['T'] = time();

    $out = get_output_datapoints($seconds_since_last_iteration, $vals, $errors_per);

    echo "\n";
    foreach ($out as $data_point) {
        $align = '';
        if (isset($data_point[3])) {
            $align = '-';
        }
        printf(get_output_template($data_point[2], $align), $data_point[0], $data_point[1]);
    }

    sleep($iteration_wait_time);
}


// @todo move target to arg
function ping_time($ping_target = '8.8.8.8')
{
    ob_start();
    $ping = [];
    exec('ping -c 1 -q -Q -t 1 -W 1 '.$ping_target, $ping);
    if (isset($ping[4])) {
        $time = explode('/', $ping[4]);
        if (isset($time[4])) {
            echo $time[4];
        }
    } else {
        echo '--';
    }
    return ob_get_clean();
}

function diff($val1, $val2)
{
    return (int)$val1 - (int)$val2;
}

function get_error_rate($this_iter_error, $last_iter_error, $seconds_since_last_iteration)
{
    $diff = diff($this_iter_error, $last_iter_error);
    if ($diff > 0) {
        return round($diff / $seconds_since_last_iteration, 2);
    }
    return 0;
}

function get_output_template($padding_length = 6, $align = '')
{
    return "| %s %' $align{$padding_length}s ";
}

function get_output_datapoints($seconds_since_last_iteration, $vals, $errors_per)
{
    return [
        [
            '',
            date('ymd H:i:s'),
            15
        ],
        [
            'il',
            $seconds_since_last_iteration,
            3
        ],
        [
            'sn↓',
            $vals['DN']['signal_to_noise_margin'],
            4
        ],
        [
            'sn↑',
            $vals['UP']['signal_to_noise_margin'],
            4
        ],
        [
            'la↓',
            $vals['DN']['line_attenuation'],
            4
        ],
        [
            'la↑',
            $vals['UP']['line_attenuation'],
            4
        ],
        [
            'sr↓',
            $vals['DN']['sync_rate'],
            6,
        ],
        [
            'sr↑',
            $vals['UP']['sync_rate'],
            5
        ],
        [
            'cr↓',
            $vals['DN']['crc_errors'].' ('.$errors_per['DN']['crc'].')',
            8,
            '-'
        ],
        [
            'cr↑',
            $vals['UP']['crc_errors'].' ('.$errors_per['UP']['crc'].')',
            8,
            '-'
        ],
        [
            'fr↓',
            $vals['DN']['fec_errors'].' ('.$errors_per['DN']['fec'].')',
            13,
            '-'
        ],
        [
            'fr↑',
            $vals['UP']['fec_errors'].' ('.$errors_per['UP']['fec'].')',
            4,
            '-'
        ],
        [
            'bc',
            $vals['ST']['broadband_connection'],
            3
        ],
        [
            'ls',
            $vals['ST']['line_state'],
            3
        ],
        [
            'gg',
            ping_time(),
            6
        ]
    ];
}
