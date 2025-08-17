#!/usr/bin/env php
<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

/**
 * OJS Native XML version converter
 *
 * Usage:
 *   php xml-convert.php --from=3.3.0 --to=3.5.0 --in=./input.xml --out=./output.xml [--debug] [--validate-strict]
 *
 * Notes:
 * - It relies on OJS\PhpTransform\Pipeline to perform the version-to-version hops.
 */

$args = $argv; array_shift($args);
$opts = [
	'debug' => false,
	'validate_strict' => false,
	'from' => null,
	'to' => null,
	'in' => null,
	'out' => null,
];

foreach ($args as $arg) {
	if (preg_match('/^--from=(.+)$/', $arg, $m)) { $opts['from'] = $m[1]; continue; }
	if (preg_match('/^--to=(.+)$/', $arg, $m)) { $opts['to'] = $m[1]; continue; }
	if (preg_match('/^--in=(.+)$/', $arg, $m)) { $opts['in'] = $m[1]; continue; }
	if (preg_match('/^--out=(.+)$/', $arg, $m)) { $opts['out'] = $m[1]; continue; }
	if ($arg === '--debug') { $opts['debug'] = true; continue; }
	if ($arg === '--validate-strict') { $opts['validate_strict'] = true; continue; }
}

if (!$opts['from']||!$opts['to']||!$opts['in']||!$opts['out']) {
	fwrite(STDERR, "[fatal] Usage: php bin/xml-convert.php --from=X --to=Y --in=in.xml --out=out.xml [--debug] [--validate-strict]\n");
	exit(1);
}

$cwd=getcwd();
$candidates=[
	$cwd.'/vendor/autoload.php',
	$cwd.'/autoload.php',
	$cwd.'/src/autoload.php',
	$cwd.'/src/PhpTransform/autoload.php',
];

$loaded=false;
foreach ($candidates as $cand) { 
	if (is_file($cand)) { 
		require_once $cand; 
		$loaded=true; 
		break; 
	} 
}

if (!$loaded) {
	fwrite(STDERR,"[fatal] Could not locate autoload. Tried:\n  - ".implode("\n  - ",$candidates)."\n"); exit(1);
}

use OJS\PhpTransform\Pipeline;
use OJS\PhpTransform\Util\Xml;

fwrite(STDERR, "[cli] Loading input: {$opts['in']}\n");

$doc = new DOMDocument('1.0','UTF-8');
$doc->preserveWhiteSpace=false; 
$doc->formatOutput=true;

if (!$doc->load($opts['in'])) { 
	fwrite(STDERR,"[fatal] Failed to parse input XML.\n"); 
	exit(1); 
}

$debug = (bool) $opts['debug'];
$validateStrict = (bool) $opts['validate_strict'];

fwrite(STDERR, "[cli] Running pipeline {$opts['from']} → {$opts['to']} (debug=" . ($debug?'on':'off') . ", validate=" . ($validateStrict?'strict':'off') . ")\n");

try {
	$pipeline = new Pipeline($validateStrict);
	$outDoc = $pipeline->run($doc, $opts['from'], $opts['to'], $debug);

	// final validation against target
	if ($validateStrict) {
		$ref = new ReflectionClass(Pipeline::class);
		
		$m = $ref->getMethod('xsdPathForVersion'); 
		$m->setAccessible(true);
		$xsdPath = $m->invoke($pipeline, $opts['to']);
		
		if ($xsdPath) {
			Xml::dbg(true,0,'🧪',"Final validation against {$opts['to']} native.xsd …");
			
			Xml::validateOrThrow($outDoc, $xsdPath, "final@{$opts['to']}");
			
			Xml::dbg(true,0,'✅',"Final document is valid for {$opts['to']}");
		} else {
			Xml::dbg(true,0,'🧪',"Skipping final validation for {$opts['to']} (no XSD found).");
		}
	}

	if (!$outDoc->save($opts['out'])) { 
		fwrite(STDERR,"[fatal] Failed to write output.\n"); 
		exit(1); 
	}

	fwrite(STDERR,"[cli] Wrote: {$opts['out']}\n");
	exit(0);
} catch(Throwable $e) {
	fwrite(STDERR,"[fatal] ".get_class($e).": ".$e->getMessage()."\n");
	if ($debug) {
		fwrite(STDERR,$e->getTraceAsString()."\n");
	}

	exit(1);
}
