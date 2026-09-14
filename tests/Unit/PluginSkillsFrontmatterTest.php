<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * Regression guard: every plugin-shipped `SKILL.md` must parse cleanly
 * through the same YAML library spora-core's SkillScanner uses, and the
 * parsed frontmatter must declare the keys SkillValidator requires
 * (name / description). Otherwise the skill silently vanishes from the
 * Agent settings UI because `SkillScanner::parseFrontmatter()` returns
 * `[null, '']` on `Yaml::parse()` failure and `SkillValidator` rejects
 * malformed frontmatter.
 *
 * Background
 * ----------
 * `muse-image/SKILL.md` shipped in feat/stt-and-image with an
 * unquoted YAML description whose body contained `Two operations:
 * `generate` …`. Symfony YAML enforces a strict scalar rule against
 * `: ` (colon-space) inside an unquoted mapping value, so
 * `Yaml::parse()` threw "A colon cannot be used in an unquoted
 * mapping value at line 2". The skill vanished from the gallery
 * without any visible error. Single-quoting the description (`'…'`)
 * fixed it. This test pins that contract so a later copy-paste
 * regression can't sneak it back.
 */
it('every plugin-shipped SKILL.md parses via the same YAML library the SkillScanner uses', function (string $slug, string $dir): void {
    $path = $dir . '/SKILL.md';
    expect(is_file($path))->toBeTrue("missing {$path}");

    $contents = file_get_contents($path);
    expect($contents)->toStartWith("---\n");

    expect((bool) preg_match('/^---\n(.*?)\n---/s', $contents, $m))->toBeTrue(
        "'{$path}' does not have a closing '---' delimiter for the YAML block.",
    );

    $body = ltrim(substr($contents, strlen($m[0])), "\n\r");
    expect($body)->not->toBeEmpty("'{$path}' has no Markdown body after the frontmatter.");

    $parsed = Yaml::parse($m[1]);

    expect($parsed)->toBeArray('YAML parse must yield an object (mapping).');
    expect($parsed)->toHaveKeys(['name', 'description'], 'name + description are mandatory per the agentskills.io spec.');

    expect($parsed['name'])->toBe($slug, "name must equal the parent directory ('{$slug}') so SkillValidator's NAME_DIR_MISMATCH rule passes.");
    expect($parsed['description'])->toBeString();
    expect($parsed['description'])->not->toBe('');
    expect(strlen($parsed['description']))->toBeLessThanOrEqual(1024);
})->with([
    ['muse-image', __DIR__ . '/../../skills/muse-image'],
]);
