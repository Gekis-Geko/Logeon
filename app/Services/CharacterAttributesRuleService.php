<?php

declare(strict_types=1);

namespace App\Services;

use Core\Http\AppError;

class CharacterAttributesRuleService extends CharacterAttributesBaseService
{
    private const OPERATORS = ['set', 'add', 'sub', 'mul', 'div', 'min', 'max'];
    private const OPERAND_TYPES = ['attribute', 'value'];
    private const ROUND_MODES = ['none', 'floor', 'ceil', 'round'];

    /** @var CharacterAttributesDefinitionService */
    private $definitions;

    public function __construct(
        \Core\Database\DbAdapterInterface $db = null,
        CharacterAttributesDefinitionService $definitions = null,
    ) {
        parent::__construct($db);
        $this->definitions = $definitions ?: new CharacterAttributesDefinitionService($this->db);
    }

    private function firstPrepared(string $sql, array $params = [])
    {
        return $this->db->fetchOnePrepared($sql, $params);
    }

    private function fetchPrepared(string $sql, array $params = []): array
    {
        return $this->db->fetchAllPrepared($sql, $params);
    }

    private function execPrepared(string $sql, array $params = []): void
    {
        $this->db->executePrepared($sql, $params);
    }

    public function getRuleByAttributeId(int $attributeId): array
    {
        if ($attributeId <= 0) {
            throw AppError::validation('Attributo non valido', [], 'attribute_definition_not_found');
        }

        $definition = $this->definitions->getDefinitionById($attributeId);
        if (empty($definition)) {
            throw AppError::notFound('Attributo non trovato', [], 'attribute_definition_not_found');
        }

        $rule = $this->firstPrepared(
            'SELECT
                r.id,
                r.attribute_id,
                r.is_active,
                r.fallback_value,
                r.round_mode,
                r.date_created,
                r.date_updated
             FROM character_attribute_rules r
             WHERE r.attribute_id = ?
             LIMIT 1',
            [$attributeId],
        );

        $steps = [];
        if (!empty($rule)) {
            $steps = $this->fetchPrepared(
                'SELECT
                    s.id,
                    s.rule_id,
                    s.step_order,
                    s.operator_code,
                    s.operand_type,
                    s.operand_attribute_id,
                    s.operand_value
                 FROM character_attribute_rule_steps s
                 WHERE s.rule_id = ?
                 ORDER BY s.step_order ASC, s.id ASC',
                [(int) $rule->id],
            );
        }

        return [
            'definition' => $definition,
            'rule' => !empty($rule) ? $rule : null,
            'steps' => $steps ?: [],
        ];
    }

    public function upsertRule(int $attributeId, object $data): array
    {
        if ($attributeId <= 0) {
            throw AppError::validation('Attributo non valido', [], 'attribute_definition_not_found');
        }

        $definition = $this->definitions->getDefinitionById($attributeId);
        if (empty($definition)) {
            throw AppError::notFound('Attributo non trovato', [], 'attribute_definition_not_found');
        }
        if ((int) ($definition->is_derived ?? 0) !== 1) {
            throw AppError::validation(
                'Le regole sono consentite solo per attributi derivati',
                [],
                'attribute_rule_invalid',
            );
        }

        $rawSteps = isset($data->steps) && is_array($data->steps) ? $data->steps : [];
        if (empty($rawSteps)) {
            throw AppError::validation('Regola non valida: aggiungi almeno uno step', [], 'attribute_rule_invalid');
        }

        $normalizedSteps = $this->normalizeSteps($rawSteps, $attributeId);
        $fallbackValue = $this->normalizeDecimalValue(
            property_exists($data, 'fallback_value') ? $data->fallback_value : null,
            'fallback regola',
            'attribute_rule_invalid',
            true,
        );
        $isActive = $this->normalizeBool($data->is_active ?? 1, 1);
        $roundMode = null;
        if (property_exists($data, 'round_mode')) {
            $roundModeRaw = strtolower(trim((string) $data->round_mode));
            if ($roundModeRaw !== '') {
                if (!in_array($roundModeRaw, self::ROUND_MODES, true)) {
                    throw AppError::validation('Round regola non valido', [], 'attribute_rule_invalid');
                }
                $roundMode = $roundModeRaw;
            }
        }

        $this->begin();
        try {
            $rule = $this->firstPrepared(
                'SELECT id
                 FROM character_attribute_rules
                 WHERE attribute_id = ?
                 LIMIT 1
                 FOR UPDATE',
                [$attributeId],
            );

            $ruleId = 0;
            if (!empty($rule) && isset($rule->id)) {
                $ruleId = (int) $rule->id;
                $this->execPrepared(
                    'UPDATE character_attribute_rules SET
                        is_active = ?,
                        fallback_value = ?,
                        round_mode = ?,
                        date_updated = NOW()
                     WHERE id = ?',
                    [$isActive, $fallbackValue, $roundMode, $ruleId],
                );
                $this->execPrepared(
                    'DELETE FROM character_attribute_rule_steps
                     WHERE rule_id = ?',
                    [$ruleId],
                );
            } else {
                $this->execPrepared(
                    'INSERT INTO character_attribute_rules SET
                        attribute_id = ?,
                        is_active = ?,
                        fallback_value = ?,
                        round_mode = ?,
                        date_created = NOW()',
                    [$attributeId, $isActive, $fallbackValue, $roundMode],
                );
                $ruleId = (int) $this->db->lastInsertId();
            }

            if ($ruleId <= 0) {
                throw AppError::validation('Salvataggio regola non riuscito', [], 'attribute_rule_invalid');
            }

            foreach ($normalizedSteps as $step) {
                $this->execPrepared(
                    'INSERT INTO character_attribute_rule_steps SET
                        rule_id = ?,
                        step_order = ?,
                        operator_code = ?,
                        operand_type = ?,
                        operand_attribute_id = ?,
                        operand_value = ?,
                        date_created = NOW()',
                    [
                        $ruleId,
                        $step['step_order'],
                        $step['operator_code'],
                        $step['operand_type'],
                        $step['operand_attribute_id'],
                        $step['operand_value'],
                    ],
                );
            }

            $this->assertNoCircularDependencies();
            $this->commit();
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }

        return $this->getRuleByAttributeId($attributeId);
    }

    public function deleteRuleByAttributeId(int $attributeId): array
    {
        if ($attributeId <= 0) {
            throw AppError::validation('Attributo non valido', [], 'attribute_definition_not_found');
        }

        $rule = $this->firstPrepared(
            'SELECT id
             FROM character_attribute_rules
             WHERE attribute_id = ?
             LIMIT 1',
            [$attributeId],
        );

        if (empty($rule) || !isset($rule->id)) {
            return ['deleted' => false];
        }

        $ruleId = (int) $rule->id;
        $this->begin();
        try {
            $this->execPrepared(
                'DELETE FROM character_attribute_rule_steps
                 WHERE rule_id = ?',
                [$ruleId],
            );
            $this->execPrepared(
                'DELETE FROM character_attribute_rules
                 WHERE id = ?',
                [$ruleId],
            );
            $this->commit();
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }

        return ['deleted' => true];
    }

    public function activeRulesMap(): array
    {
        $rules = $this->fetchPrepared(
            'SELECT
                r.id,
                r.attribute_id,
                r.fallback_value,
                r.round_mode,
                r.is_active
             FROM character_attribute_rules r
             WHERE r.is_active = 1',
        );

        if (empty($rules)) {
            return [];
        }

        $map = [];
        foreach ($rules as $rule) {
            $ruleId = (int) ($rule->id ?? 0);
            if ($ruleId <= 0) {
                continue;
            }
            $steps = $this->fetchPrepared(
                'SELECT
                    s.step_order,
                    s.operator_code,
                    s.operand_type,
                    s.operand_attribute_id,
                    s.operand_value
                 FROM character_attribute_rule_steps s
                 WHERE s.rule_id = ?
                 ORDER BY s.step_order ASC, s.id ASC',
                [$ruleId],
            );

            $map[(int) $rule->attribute_id] = [
                'rule' => $rule,
                'steps' => $steps ?: [],
            ];
        }

        return $map;
    }

    public function assertNoCircularDependencies(): void
    {
        $definitions = $this->definitions->listActiveDefinitions();
        $derivedIds = [];
        foreach ($definitions as $definition) {
            if ((int) ($definition->is_derived ?? 0) === 1) {
                $derivedIds[(int) $definition->id] = true;
            }
        }

        if (empty($derivedIds)) {
            return;
        }

        $rows = $this->fetchPrepared(
            'SELECT
                r.attribute_id,
                s.operand_attribute_id
             FROM character_attribute_rules r
             LEFT JOIN character_attribute_rule_steps s ON s.rule_id = r.id
             WHERE r.is_active = 1
               AND s.operand_type = "attribute"
               AND s.operand_attribute_id IS NOT NULL',
        );

        $graph = [];
        foreach (array_keys($derivedIds) as $attrId) {
            $graph[$attrId] = [];
        }

        foreach ($rows ?: [] as $row) {
            $source = (int) ($row->attribute_id ?? 0);
            $target = (int) ($row->operand_attribute_id ?? 0);
            if ($source <= 0 || $target <= 0) {
                continue;
            }
            if (!isset($derivedIds[$source]) || !isset($derivedIds[$target])) {
                continue;
            }
            $graph[$source][] = $target;
        }

        $state = []; // 0: not visited, 1: in stack, 2: done
        $stack = [];
        $visit = function ($node) use (&$visit, &$state, &$graph, &$stack) {
            $state[$node] = 1;
            $stack[$node] = true;

            foreach ($graph[$node] as $next) {
                $nextState = $state[$next] ?? 0;
                if ($nextState === 1) {
                    throw AppError::validation(
                        'Regola attributi non valida: dipendenze cicliche rilevate',
                        [],
                        'attribute_rule_invalid',
                    );
                }
                if ($nextState === 0) {
                    $visit($next);
                }
            }

            unset($stack[$node]);
            $state[$node] = 2;
        };

        foreach (array_keys($graph) as $node) {
            if (($state[$node] ?? 0) === 0) {
                $visit($node);
            }
        }
    }

    private function normalizeSteps(array $steps, int $attributeId): array
    {
        $normalized = [];
        $stepOrder = 1;

        foreach ($steps as $index => $entry) {
            $step = is_object($entry) ? $entry : (object) $entry;

            $operator = strtolower(trim((string) ($step->operator_code ?? 'set')));
            if (!in_array($operator, self::OPERATORS, true)) {
                throw AppError::validation('Operatore step non valido', [], 'attribute_rule_step_invalid');
            }

            $operandType = strtolower(trim((string) ($step->operand_type ?? 'value')));
            if (!in_array($operandType, self::OPERAND_TYPES, true)) {
                throw AppError::validation('Tipo operando step non valido', [], 'attribute_rule_step_invalid');
            }

            if ($stepOrder === 1 && $operator !== 'set') {
                throw AppError::validation('Il primo step deve usare operatore SET', [], 'attribute_rule_step_invalid');
            }

            $operandAttributeId = null;
            $operandValue = null;

            if ($operandType === 'attribute') {
                $operandAttributeId = (int) ($step->operand_attribute_id ?? 0);
                if ($operandAttributeId <= 0) {
                    throw AppError::validation('Attributo operando non valido', [], 'attribute_rule_step_invalid');
                }
                if ($operandAttributeId === $attributeId) {
                    throw AppError::validation('Dipendenza ciclica diretta non consentita', [], 'attribute_rule_step_invalid');
                }

                $operandDefinition = $this->definitions->getDefinitionById($operandAttributeId);
                if (empty($operandDefinition)) {
                    throw AppError::validation('Attributo operando non trovato', [], 'attribute_rule_step_invalid');
                }
            } else {
                $operandValue = $this->normalizeDecimalValue(
                    property_exists($step, 'operand_value') ? $step->operand_value : null,
                    'operando numerico',
                    'attribute_rule_step_invalid',
                    false,
                );
            }

            if ($operator === 'div' && $operandType === 'value' && abs((float) $operandValue) < 0.0000001) {
                throw AppError::validation('Divisione per zero non valida', [], 'attribute_rule_step_invalid');
            }

            $normalized[] = [
                'step_order' => $stepOrder,
                'operator_code' => $operator,
                'operand_type' => $operandType,
                'operand_attribute_id' => $operandAttributeId,
                'operand_value' => $operandValue,
            ];
            $stepOrder++;
        }

        return $normalized;
    }
}
