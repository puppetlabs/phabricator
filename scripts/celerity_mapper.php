#!/usr/bin/env php
<?php

require_once dirname(__FILE__).'/__init_script__.php';

if ($argc != 2) {
  $self = basename($argv[0]);
  echo "usage: {$self} <webroot>\n";
  exit(1);
}

phutil_require_module('phutil', 'filesystem');
phutil_require_module('phutil', 'filesystem/filefinder');
phutil_require_module('phutil', 'future/exec');
phutil_require_module('phutil', 'parser/docblock');

$root = Filesystem::resolvePath($argv[1]);

echo "Finding static resources...\n";
$files = id(new FileFinder($root))
  ->withType('f')
  ->withSuffix('js')
  ->withSuffix('css')
  ->setGenerateChecksums(true)
  ->find();

echo "Processing ".count($files)." files";

$file_map = array();
foreach ($files as $path => $hash) {
  echo ".";
  $name = '/'.Filesystem::readablePath($path, $root);
  $file_map[$name] = array(
    'hash' => $hash,
    'disk' => $path,
  );
}
echo "\n";

$runtime_map = array();

$parser = new PhutilDocblockParser();
foreach ($file_map as $path => $info) {
  $data = Filesystem::readFile($info['disk']);
  $matches = array();
  $ok = preg_match('@/[*][*].*?[*]/@s', $data, $matches);
  if (!$ok) {
    throw new Exception(
      "File {$path} does not have a header doc comment. Encode dependency ".
      "data in a header docblock.");
  }
  
  list($description, $metadata) = $parser->parse($matches[0]);
  
  $provides = preg_split('/\s+/', trim(idx($metadata, 'provides')));
  $requires = preg_split('/\s+/', trim(idx($metadata, 'requires')));
  $provides = array_filter($provides);
  $requires = array_filter($requires);
  
  if (count($provides) !== 1) {
    throw new Exception(
      "File {$path} must @provide exactly one Celerity target.");
  }
  
  $provides = reset($provides);

  $type = 'js';
  if (preg_match('/\.css$/', $path)) {
    $type = 'css';
  }
  
  $path = '/res/'.substr($info['hash'], 0, 8).$path;
  
  $runtime_map[$provides] = array(
    'path'      => $path,
    'type'      => $type,
    'requires'  => $requires,
  );
}

$runtime_map = var_export($runtime_map, true);
$runtime_map = preg_replace('/\s+$/m', '', $runtime_map);
$runtime_map = preg_replace('/array \(/', 'array(', $runtime_map);

$resource_map = <<<EOFILE
<?php

/**
 * This file is automatically generated. Use 'celerity_mapper.php' to rebuild
 * it.
 * @generated
 */

celerity_register_resource_map({$runtime_map});

EOFILE;

echo "Writing map...\n";
Filesystem::writeFile(
  $root.'/../src/__celerity_resource_map__.php',
  $resource_map);
echo "Done.\n";  