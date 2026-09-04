<?php
return [
    'max_idea_length' => 6000,
    'rounds' => [
        'Раунд 1: Независимый первичный анализ идеи',
        'Раунд 2: Критика и уточнение предыдущих выводов: обсуждение противоречий и поиск решений.',
        'Раунд 3: Финальная позиция: обсуждение рисков и конкретных рекомендаций',
    ],
    'agents' => [
        'product' => [
            'name' => 'Продакт-менеджер',
            'base_url' => 'https://agent.timeweb.cloud/api/v1/cloud-ai/agents/PUT_PRODUCT_ID/v1',
            'token' => 'PUT_PRODUCT_TOKEN',
            'model' => 'deepseek/deepseek-v4-flash',
            'role' => 'Ты опытный Product Manager. Анализируй бизнес-ценность идеи, целевую аудиторию, проблему, JTBD, MVP, метрики и продуктовые гипотезы. Будь конкретным.',
        ],
        'project' => [
            'name' => 'Проджект-менеджер',
            'base_url' => 'https://agent.timeweb.cloud/api/v1/cloud-ai/agents/PUT_PROJECT_ID/v1',
            'token' => 'PUT_PROJECT_TOKEN',
            'model' => 'deepseek/deepseek-v4-flash',
            'role' => 'Ты опытный Project Manager. Оцени реализацию, декомпозицию, зависимости, риски, порядок работ, критерии готовности и реалистичный roadmap. Опирайся на предыдущие мнения.',
        ],
        'backend' => [
            'name' => 'Backend-разработчик',
            'base_url' => 'https://agent.timeweb.cloud/api/v1/cloud-ai/agents/PUT_BACKEND_ID/v1',
            'token' => 'PUT_BACKEND_TOKEN',
            'model' => 'deepseek/deepseek-v4-flash',
            'role' => 'Ты senior Python backend developer. Предложи практичную архитектуру, API, модель данных, безопасность, интеграции, фоновые задачи, тестирование и стек. Не усложняй MVP без причины.',
        ],
        'frontend' => [
            'name' => 'Frontend-разработчик и дизайнер',
            'base_url' => 'https://agent.timeweb.cloud/api/v1/cloud-ai/agents/PUT_FRONTEND_ID/v1',
            'token' => 'PUT_FRONTEND_TOKEN',
            'model' => 'deepseek/deepseek-v4-flash',
            'role' => 'Ты senior frontend developer и product designer. Продумай UX, пользовательский путь, responsive UI, состояния интерфейса, визуальный язык и frontend-архитектуру. Учитывай предыдущие решения команды.',
        ],
    ],
    // Необязательно: если токены PUT_, синтез идёт через продакт-агента.
    'chair' => [
        'name' => 'Председатель',
        'base_url' => 'https://agent.timeweb.cloud/api/v1/cloud-ai/agents/PUT_CHAIR_ID/v1',
        'token' => 'PUT_CHAIR_TOKEN',
        'model' => 'deepseek/deepseek-v4-flash',
        'role' => 'Ты стратегический руководитель продукта и технический архитектор. Синтезируй мнения команды в практичное решение.',
    ],
];
