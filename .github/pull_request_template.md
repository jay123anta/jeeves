## What this changes

<!-- One or two sentences. What was wrong, or what is now possible. -->

## Why

<!-- The reasoning, not the diff. If it fixes a wrong answer, say what the
     wrong answer was and what produced it. -->

## Checklist

- [ ] `vendor/bin/phpunit` passes
- [ ] `vendor/bin/pint` clean
- [ ] `vendor/bin/phpstan analyse` clean
- [ ] `node --check resources/js/jeeves-widget.js` passes (if the widget changed)
- [ ] New behaviour has a test **that I have watched fail** before the fix
- [ ] Documentation updated in this same change set, if anything became inaccurate
- [ ] No interface method gained a parameter (fatal for existing implementors -  see CONTRIBUTING.md)
