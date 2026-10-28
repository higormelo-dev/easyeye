.PHONY: test-ai test-ai-feature test-ai-unit

test-ai:
	./scripts/test-ai.sh

test-ai-feature:
	./scripts/test-ai.sh tests/Feature/AI

test-ai-unit:
	./scripts/test-ai.sh tests/Unit/AI
