## Golden rules
- Make the smallest change that solves the task.
- Prefer editing existing files over introducing new abstractions.
- Do not refactor unrelated code.
- If something is unclear, inspect the codebase first before adding new patterns.
- Keep output deterministic: no random IDs, no time-based behavior unless explicitly requested.

## Language
- Site UI text must be in Slovak, English, Polish, Hungarian. Swedish, Chinese.
- Assistant responses must be in Russian.
- Do not replace Slovak text with Unicode escape sequences; keep strings readable.
- Keep source files in UTF-8 (no BOM) to avoid broken Slovak characters.