<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The agent roster exists twice — `.claude/agents/` for Claude Code and
 * `.qwen/agents/` for Qwen Code — and nothing but this test keeps the two in
 * step. Qwen Code reads `QWEN.md` and `AGENTS.md` and never `CLAUDE.md`, so
 * the project context and the nine definitions were invisible to it until they
 * were mirrored; both context files now say "edit both copies", and an
 * instruction kept in step by nothing but a comment is the same defect one
 * level up from the one being caught (see PreflightDatabaseEngineTest).
 *
 * The failure this guards against is quiet. An agent edited on one side only
 * still loads, still runs and still reports a green handoff — it simply
 * carries a rule the other tool's copy does not, so the two gates reach
 * different verdicts on the same diff depending on which CLI reviewed it.
 *
 * Reads the files directly rather than through any loader, so the guard cannot
 * be satisfied by one tool's cache or by a definition that parses on one side
 * and is silently ignored on the other.
 */
class AgentRosterMirrorTest extends TestCase
{
    /**
     * Claude Code tool name => Qwen Code tool name, as documented in QWEN.md.
     *
     * @var array<string, string>
     */
    private const TOOL_MAP = [
        'Read' => 'read_file',
        'Write' => 'write_file',
        'Edit' => 'edit',
        'Glob' => 'glob',
        'Grep' => 'grep_search',
        'Bash' => 'run_shell_command',
        'WebSearch' => 'web_search',
        'WebFetch' => 'web_fetch',
    ];

    /**
     * Claude Code model tier => Qwen Code model selector, as documented in
     * QWEN.md. `inherit` is the session model; `fast` is the configured
     * fastModel, falling back to `inherit` while none is set.
     *
     * @var array<string, string>
     */
    private const MODEL_MAP = [
        'opus' => 'inherit',
        'sonnet' => 'fast',
    ];

    /**
     * The two bodies that name a capability only one tool has. Each entry
     * states what must be present in the Claude copy, what must have gone from
     * the Qwen copy, and what must have replaced it there.
     *
     * @var array<string, array{claude_has: list<string>, qwen_lacks: list<string>, qwen_has: list<string>}>
     */
    private const BODY_EXCEPTIONS = [
        'code-reviewer' => [
            'claude_has' => ['`Bash`'],
            'qwen_lacks' => ['`Bash`'],
            'qwen_has' => ['`run_shell_command`'],
        ],
        'frontend-engineer' => [
            'claude_has' => ['mcp__Claude_Browser__'],
            'qwen_lacks' => ['mcp__Claude_Browser__', 'Browser pane'],
            'qwen_has' => ['qwen mcp approve playwright'],
        ],
    ];

    /**
     * Tools no agent on either side may be given, whatever the server offering
     * them is called. `browser_run_code_unsafe` executes arbitrary Playwright
     * code in the driver process; `browser_evaluate` covers the DOM assertions
     * a front-end check actually needs, inside the page.
     *
     * @var list<string>
     */
    private const FORBIDDEN_TOOLS = ['browser_run_code_unsafe'];

    #[Test]
    public function qwen_md_still_imports_claude_md(): void
    {
        $qwen = base_path('QWEN.md');
        $claude = base_path('CLAUDE.md');

        $this->assertFileExists($claude, 'CLAUDE.md is gone; QWEN.md would import nothing and Qwen Code would start with no project context at all.');
        $this->assertFileExists($qwen, 'QWEN.md is gone; Qwen Code reads no context file in this repository.');

        $this->assertStringContainsString(
            '@CLAUDE.md',
            (string) file_get_contents($qwen),
            'QWEN.md no longer imports CLAUDE.md. The import is what keeps one source of truth: without it, Qwen Code '
            .'sessions start without the database gap, the module map or the two gates, and nothing else warns about it.'
        );
    }

    #[Test]
    public function the_two_rosters_name_the_same_agents(): void
    {
        $claude = $this->roster('.claude/agents');
        $qwen = $this->roster('.qwen/agents');

        $this->assertNotEmpty($claude, 'No agent definitions found in .claude/agents, so there is nothing to mirror.');

        $this->assertSame(
            $claude,
            $qwen,
            sprintf(
                "The rosters have drifted.\nOnly in .claude/agents: [%s]\nOnly in .qwen/agents: [%s]\n".
                'An agent added to one tool and not the other is invisible to the second, so a phase delegated there '
                .'runs without its gate. Add the definition to both directories.',
                implode(', ', array_diff($claude, $qwen)),
                implode(', ', array_diff($qwen, $claude)),
            )
        );
    }

    #[Test]
    public function every_mirrored_frontmatter_follows_the_documented_mapping(): void
    {
        foreach ($this->roster('.claude/agents') as $name) {
            $claude = $this->definition(base_path(".claude/agents/{$name}.md"));
            $qwen = $this->definition(base_path(".qwen/agents/{$name}.md"));

            $this->assertSame($name, $claude['name'], ".claude/agents/{$name}.md declares a name that is not its filename, so the tool cannot address it by that name.");
            $this->assertSame($name, $qwen['name'], ".qwen/agents/{$name}.md declares a name that is not its filename, so the tool cannot address it by that name.");

            $this->assertSame(
                $claude['description'],
                $qwen['description'],
                "{$name}: the descriptions differ. The description is what decides when an agent is delegated to, so a "
                .'drifted one sends the wrong work to the gate on one tool and the right work on the other.'
            );

            $this->assertArrayHasKey(
                $claude['model'],
                self::MODEL_MAP,
                "{$name}: `.claude` declares model `{$claude['model']}`, which MODEL_MAP does not cover. Add its Qwen "
                .'selector to the map in this test and to the table in QWEN.md rather than guessing one into the mirror.'
            );

            $this->assertSame(
                self::MODEL_MAP[$claude['model']],
                $qwen['model'],
                "{$name}: `.claude` runs `{$claude['model']}`, so `.qwen` must run `".self::MODEL_MAP[$claude['model']].'`.'
            );

            $this->assertSame(
                $this->mappedTools($claude['tools']),
                $qwen['tools'],
                "{$name}: the tool lists are not the mapping of one another. Claude names are on the left, the Qwen "
                .'names they must become on the right. MCP tools are excluded from both sides — an MCP name encodes '
                .'its own server, so the two tools can never share one.'
            );
        }
    }

    #[Test]
    public function bodies_are_identical_apart_from_the_two_that_named_a_claude_only_tool(): void
    {
        foreach ($this->roster('.claude/agents') as $name) {
            $claudeBody = $this->definition(base_path(".claude/agents/{$name}.md"))['body'];
            $qwenBody = $this->definition(base_path(".qwen/agents/{$name}.md"))['body'];

            if (! isset(self::BODY_EXCEPTIONS[$name])) {
                $this->assertSame(
                    $claudeBody,
                    $qwenBody,
                    "{$name}: the bodies differ. Everything below the frontmatter is meant to be byte-identical, so "
                    .'an edit made to one copy has to be made to the other. If this agent now genuinely needs a '
                    .'tool-specific passage, add it to BODY_EXCEPTIONS with what each side must and must not say — do '
                    .'not weaken this assertion to a comparison of lengths or headings.'
                );

                continue;
            }

            $exception = self::BODY_EXCEPTIONS[$name];

            foreach ($exception['claude_has'] as $needle) {
                $this->assertStringContainsString($needle, $claudeBody, "{$name}: `.claude` no longer says {$needle}, so BODY_EXCEPTIONS is describing a body that does not exist. Update the exception.");
            }

            foreach ($exception['qwen_lacks'] as $needle) {
                $this->assertStringNotContainsString($needle, $qwenBody, "{$name}: `.qwen` still says {$needle}, which names a capability Qwen Code does not have. The agent will claim a tool it cannot call.");
            }

            foreach ($exception['qwen_has'] as $needle) {
                $this->assertStringContainsString($needle, $qwenBody, "{$name}: `.qwen` no longer says {$needle}, which is what replaced the Claude-only capability there.");
            }
        }
    }

    #[Test]
    public function no_agent_is_given_a_tool_that_runs_arbitrary_code_in_the_driver(): void
    {
        foreach (['.claude/agents', '.qwen/agents'] as $dir) {
            foreach ($this->roster($dir) as $name) {
                $allowed = $this->definition(base_path("{$dir}/{$name}.md"))['allowed_tools'];

                foreach (self::FORBIDDEN_TOOLS as $forbidden) {
                    // Matched as a substring, not by equality: an MCP tool
                    // arrives qualified with its server, so the entry in the
                    // list reads mcp__playwright__browser_run_code_unsafe.
                    $granted = array_values(array_filter(
                        $allowed,
                        static fn (string $tool): bool => str_contains($tool, $forbidden)
                    ));

                    $this->assertSame(
                        [],
                        $granted,
                        "{$dir}/{$name}.md allows [".implode(', ', $granted).']. It runs arbitrary code in the '
                        .'browser driver process rather than in the page, which is a wider grant than any screen check '
                        .'needs. browser_evaluate runs the same assertions inside the page.'
                    );
                }
            }
        }
    }

    /**
     * The agent names in a roster directory, sorted so two rosters compare as
     * sets rather than in whichever order the filesystem happened to return.
     *
     * @return list<string>
     */
    private function roster(string $relativeDir): array
    {
        $dir = base_path($relativeDir);

        if (! is_dir($dir)) {
            return [];
        }

        $names = array_map(
            static fn (string $file): string => basename($file, '.md'),
            array_values(array_filter(
                (array) scandir($dir),
                static fn (string $file): bool => str_ends_with($file, '.md'),
            )),
        );

        sort($names);

        return $names;
    }

    /**
     * Read one definition's frontmatter and body.
     *
     * Hand-parsed rather than put through a YAML library: the frontmatter is
     * four known keys, `symfony/yaml` is a transitive dependency this suite
     * does not declare, and PreflightDatabaseEngineTest already reads ci.yml
     * the same way. A definition this parser cannot read fails the test rather
     * than being skipped, so a reformatted file cannot slip past the guard.
     *
     * @return array{name: string, description: string, model: string, tools: list<string>, allowed_tools: list<string>, body: string}
     */
    private function definition(string $path): array
    {
        // Named separately from the frontmatter check below. The likeliest
        // drift is an agent that exists on one side only, and reporting that
        // as "no frontmatter block" would send whoever reads it looking for a
        // malformed file instead of a missing one.
        $this->assertFileExists($path, "{$path} does not exist, so one tool has an agent the other does not.");

        $raw = file_get_contents($path);

        if ($raw === false || ! preg_match('/\A---\R(.*?)\R---\R(.*)\z/s', $raw, $matches)) {
            $this->fail("{$path} has no `---` frontmatter block, so neither tool can load it as an agent definition.");
        }

        [, $head, $body] = $matches;
        $lines = preg_split('/\R/', $head) ?: [];

        $scalar = function (string $key) use ($lines, $path): string {
            foreach ($lines as $line) {
                if (preg_match('/^'.preg_quote($key, '/').':[ \t]*(.+?)[ \t]*$/', $line, $m)) {
                    return $m[1];
                }
            }

            $this->fail("{$path} declares no `{$key}:` in its frontmatter.");
        };

        $tools = $this->tools($lines, $path);

        return [
            'name' => $scalar('name'),
            'description' => $scalar('description'),
            'model' => $scalar('model'),
            'tools' => $this->normalise($tools),
            'allowed_tools' => $tools,
            'body' => $body,
        ];
    }

    /**
     * The `tools:` list, in either of the two shapes the roster uses: Claude's
     * inline comma string, or Qwen's YAML block list.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function tools(array $lines, string $path): array
    {
        $index = null;

        foreach ($lines as $i => $line) {
            if (preg_match('/^tools:[ \t]*(.*)$/', $line, $m)) {
                $inline = trim($m[1]);

                if ($inline !== '') {
                    return $this->clean(explode(',', $inline));
                }

                $index = $i;

                break;
            }
        }

        if ($index === null) {
            $this->fail("{$path} declares no `tools:` list, so the agent inherits every tool the session has.");
        }

        $tools = [];

        // Only the indented `- item` lines that belong to `tools:`. The walk
        // stops at the next top-level key, so a `disallowedTools:` list added
        // below would not be read as part of this one.
        for ($i = $index + 1; $i < count($lines); $i++) {
            $line = $lines[$i];

            if (trim($line) === '') {
                continue;
            }

            if (! preg_match('/^[ \t]+-[ \t]*(.+?)[ \t]*$/', $line, $m)) {
                break;
            }

            $tools[] = $m[1];
        }

        return $this->clean($tools);
    }

    /**
     * Trim and drop empties, keeping the declared order and every entry —
     * including the MCP ones, which `normalise()` removes for the mirror
     * comparison but which a forbidden-tool check has to see.
     *
     * @param  list<string>  $tools
     * @return list<string>
     */
    private function clean(array $tools): array
    {
        return array_values(array_filter(
            array_map('trim', $tools),
            static fn (string $tool): bool => $tool !== ''
        ));
    }

    /**
     * Drop MCP tools — an MCP name encodes its own server, so the two tools can
     * never share one — then trim, drop empties and de-duplicate without
     * reordering, so the comparison is of what each side actually allows.
     *
     * @param  list<string>  $tools
     * @return list<string>
     */
    private function normalise(array $tools): array
    {
        $tools = array_filter(
            $this->clean($tools),
            static fn (string $tool): bool => ! str_starts_with($tool, 'mcp__')
        );

        return array_values(array_unique($tools));
    }

    /**
     * Claude tool names mapped to their Qwen equivalents.
     *
     * @param  list<string>  $tools
     * @return list<string>
     */
    private function mappedTools(array $tools): array
    {
        $mapped = [];

        foreach ($tools as $tool) {
            $this->assertArrayHasKey(
                $tool,
                self::TOOL_MAP,
                "`.claude` declares the tool `{$tool}`, which TOOL_MAP does not cover. Add its Qwen name to the map in "
                .'this test and to the table in QWEN.md; guessing one into the mirror is how the two rosters start '
                .'allowing different things.'
            );

            $mapped[] = self::TOOL_MAP[$tool];
        }

        return $this->normalise($mapped);
    }
}
