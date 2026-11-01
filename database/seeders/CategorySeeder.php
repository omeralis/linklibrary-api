<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

/** Default bilingual system categories (idempotent). */
class CategorySeeder extends Seeder
{
    /** @var list<array{0: string, 1: string, 2: string, 3?: string}> */
    private const CATEGORIES = [
        ['technology', 'التقنية', 'Technology'],
        ['programming', 'البرمجة', 'Programming', 'technology'],
        ['ai-data', 'الذكاء الاصطناعي والبيانات', 'AI & Data', 'technology'],
        ['cybersecurity', 'الأمن السيبراني', 'Cybersecurity', 'technology'],
        ['design', 'التصميم', 'Design'],
        ['science', 'العلوم', 'Science'],
        ['education', 'التعليم', 'Education'],
        ['business', 'الأعمال', 'Business'],
        ['finance', 'المال والاقتصاد', 'Finance & Economics'],
        ['news', 'الأخبار', 'News'],
        ['health', 'الصحة', 'Health'],
        ['culture', 'الثقافة والفنون', 'Culture & Arts'],
        ['entertainment', 'الترفيه', 'Entertainment'],
        ['sports', 'الرياضة', 'Sports'],
        ['travel', 'السفر', 'Travel'],
        ['lifestyle', 'نمط الحياة', 'Lifestyle'],
        ['productivity', 'الإنتاجية', 'Productivity'],
        ['religion', 'الدين', 'Religion'],
        ['language-learning', 'تعلم اللغات', 'Language Learning', 'education'],
        ['government', 'الحكومة والخدمات العامة', 'Government & Public Services'],
        ['other', 'أخرى', 'Other'],
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as $i => $row) {
            Category::query()->updateOrCreate(['slug' => $row[0]], [
                'name_ar' => $row[1],
                'name_en' => $row[2],
                'sort_order' => ($i + 1) * 10,
                'is_active' => true,
            ]);
        }
        foreach (self::CATEGORIES as $row) {
            if (isset($row[3])) {
                Category::query()->where('slug', $row[0])->update(['parent_id' => Category::query()->where('slug', $row[3])->value('id')]);
            }
        }
    }
}
