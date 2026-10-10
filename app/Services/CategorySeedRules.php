<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Category;

/**
 * Curated merchant → category seeds, keyed by category **full path** rather
 * than category id.
 *
 * These were originally `array<string, int>` mapping a merchant to a hardcoded
 * category id. Ids are not portable: id 35 means "Entertainment / Streaming" in
 * the database they were curated against and something else entirely anywhere
 * else, so applying them to another account would mis-file real money.
 *
 * A full path is stable across databases and is resolved against the
 * (shared) category tree at apply time. A seed whose path does not resolve is
 * **skipped and reported**, never guessed at.
 */
final class CategorySeedRules
{
    /**
     * Merchant match value => category full path.
     *
     * @var array<string, string>
     */
    private const array SEEDS = [
        'PRIMEVIDEO' => 'Entertainment / Streaming',
        'PHIND.COM' => 'Software & Online Services / AI Apps',
        'OPENAI *CHATGPT' => 'Software & Online Services / AI Apps',
        'PINECONE' => 'Software & Online Services / AI Apps',
        'RECALL' => 'Software & Online Services / AI Apps',
        'WARP PRO' => 'Software & Online Services / AI Apps',
        'SUBSTACK.COM' => 'Learning & Reading / Newsletter',
        'CODINGCHALLENGES' => 'Learning & Reading / Newsletter',
        'PADDLE.NET* DAILY.DEV' => 'Learning & Reading / Newsletter',
        'PHPARCH.COM' => 'Learning & Reading / Newsletter',
        'LEARN PROMPTING' => 'Learning & Reading / Training / Course',
        'TBL* NET NINJA' => 'Learning & Reading / Training / Course',
        'TBL* STREET-SMART' => 'Learning & Reading / Training / Course',
        'GUMROAD* MARTIN JOO' => 'Learning & Reading / Training / Course',
        'Datacamp' => 'Learning & Reading / Training / Subscription',
        'OBICO' => 'Work Equipment / 3D Printing',
        '3DEXPERIENCE' => 'Work Equipment / 3D Printing',
        'PAYPAL *CLOUDNS' => 'Software & Online Services / Online Service',
        'RESCUETIME' => 'Software & Online Services / software-subscriptions',
        'DRAWSQL' => 'Software & Online Services / software-subscriptions',
        'APIFY' => 'Software & Online Services / software-subscriptions',
        'SP LUMEN.ME' => 'Health',
        'PAYPAL *GLUCOSEGODD' => 'Health',
        'PAYPAL *MADMUSL' => 'Health',
        'HEADSPACE' => 'Health',
        'Next Practice' => 'Health',
        'PET CIRCLE' => 'Pets',
        'SP CELERY PETS' => 'Pets',
        'SP MEOWVO' => 'Pets',
        'KITTECUBE.COM' => 'Pets',
        'SP AUSSIEWOOF' => 'Pets',
        'SP RUFUS AND COCO' => 'Pets',
        'SP MICHU' => 'Pets',
        'GP VET' => 'Pets',
        'CAT SNACKS' => 'Pets',
        'HUBBL - BINGE' => 'Entertainment / Streaming',
        'Spotify' => 'Entertainment / Streaming',
        'AMZNPRIMEAU' => 'Entertainment / Streaming',
        'STEAM PURCHASE' => 'Entertainment / Gaming',
        'PAYPAL *TWITCHINTER' => 'Entertainment / Twitch',
        'PAYPAL *GISMART' => 'Software & Online Services / Mobile App',
        'PAYPAL *IMPULSE' => 'Software & Online Services / Mobile App',
        'Google XAPPIFY' => 'Software & Online Services / Mobile App',
        'Google Pujie' => 'Software & Online Services / Mobile App',
        'Google Hiya' => 'Software & Online Services / Mobile App',
        'Google SYGIC' => 'Software & Online Services / Mobile App',
        'Google OBD2 Car Scann' => 'Software & Online Services / Mobile App',
        'FLEXJOBS' => 'Personal & Shopping / Job Hunting',
        'REMOTEJOBS.IO' => 'Personal & Shopping / Job Hunting',
        'jobleads.com' => 'Personal & Shopping / Job Hunting',
        'SP THEPERFECTRESUME' => 'Personal & Shopping / Job Hunting',
        'Upwork' => 'Personal & Shopping / Job Hunting',
        'EQUIFAX' => 'Bank Fees & Finance Services',
        'HEART RESEARCH' => 'Personal & Shopping / Charity',
        'Credit Card Interest' => 'Bank Fees & Finance Services / Bank Fees',
        'Purchase Interest' => 'Bank Fees & Finance Services / Bank Fees',
        'Card Replacement Fee' => 'Bank Fees & Finance Services / Bank Fees',
        'Insufficient funds' => 'Bank Fees & Finance Services / Bank Fees',
        'LIBERTY HIGHGATE HILL' => 'Transport / Motorcycle / Fuel',
        'Reddy Express' => 'Transport / Motorcycle / Fuel',
        'LINKT' => 'Transport / Tolls',
        'READING NEWMARKET' => 'Entertainment / Event',
        'CELLOPARK' => 'Transport / Parking',
        'SQ *THE KEBAB SHOP' => 'Eating Out / Quick Foods',
        'SUBWAY' => 'Eating Out / Quick Foods',
        'EATCLUB' => 'Eating Out / Restaurant',
        'SQ *MOTORCYCLE FREIGH' => 'Transport / Motorcycle',
        'ENGINEERING LEADERSHI' => 'Learning & Reading / Newsletter',
        'PAYPAL *LUCENTGLOBE' => 'Groceries',
        'ONLYFANS' => 'Entertainment / Adult',
        'Optmus to CC' => 'Transfer / Optimus to CC',
        'AUSSIE BROADBAND LIMI' => 'Housing & Utilities / Internet',
        'LinkedIn' => 'Personal & Shopping / Job Hunting',
        'Google Workspace' => 'Software & Online Services / Online Service',
        'GSUITE' => 'Software & Online Services / Online Service',
        'Google YouTube' => 'Entertainment / Streaming',
        'GOOGLE*YOUTUBE' => 'Entertainment / Streaming',
        'WOOLWORTHS' => 'Groceries',
        'NETFLIX' => 'Entertainment / Streaming',
    ];

    public static function count(): int
    {
        return count(self::SEEDS);
    }

    /**
     * Seeds resolved against the categories that actually exist, plus the paths
     * that could not be resolved so the caller can report them.
     *
     * @return array{resolved: list<array{value: string, category_id: int, source: string}>, skipped: list<string>}
     */
    public function resolve(): array
    {
        $idsByPath = [];

        foreach (Category::allWithLinkedParents() as $category) {
            $idsByPath[mb_strtolower($category->fullPath())] = (int) $category->id;
        }

        $resolved = [];
        $skipped = [];

        foreach (self::SEEDS as $value => $path) {
            $categoryId = $idsByPath[mb_strtolower($path)] ?? null;

            if ($categoryId === null) {
                $skipped[] = sprintf('%s → %s', $value, $path);

                continue;
            }

            $resolved[] = ['value' => $value, 'category_id' => $categoryId, 'source' => 'seed'];
        }

        return ['resolved' => $resolved, 'skipped' => $skipped];
    }
}
