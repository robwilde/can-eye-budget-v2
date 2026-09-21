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
 * A full path is stable across databases and is resolved per user at apply
 * time. A seed whose path does not resolve for that user is **skipped and
 * reported**, never guessed at.
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
        'PHIND.COM' => 'Office / AI Apps',
        'OPENAI *CHATGPT' => 'Office / AI Apps',
        'PINECONE' => 'Office / AI Apps',
        'RECALL' => 'Office / AI Apps',
        'WARP PRO' => 'Office / AI Apps',
        'SUBSTACK.COM' => 'Office / Newsletter',
        'CODINGCHALLENGES' => 'Office / Newsletter',
        'PADDLE.NET* DAILY.DEV' => 'Office / Newsletter',
        'PHPARCH.COM' => 'Office / Newsletter',
        'LEARN PROMPTING' => 'Office / Training / Course',
        'TBL* NET NINJA' => 'Office / Training / Course',
        'TBL* STREET-SMART' => 'Office / Training / Course',
        'GUMROAD* MARTIN JOO' => 'Office / Training / Course',
        'Datacamp' => 'Office / Training / Subscription',
        'OBICO' => 'Office / 3D Printing',
        '3DEXPERIENCE' => 'Office / 3D Printing',
        'PAYPAL *CLOUDNS' => 'Office / Online Service',
        'RESCUETIME' => 'Office / software-subscriptions',
        'DRAWSQL' => 'Office / software-subscriptions',
        'APIFY' => 'Office / software-subscriptions',
        'SP LUMEN.ME' => 'Personal / Health',
        'PAYPAL *GLUCOSEGODD' => 'Personal / Health',
        'PAYPAL *MADMUSL' => 'Personal / Health',
        'HEADSPACE' => 'Personal / Health',
        'Next Practice' => 'Personal / Health',
        'PET CIRCLE' => 'Personal / Pet',
        'SP CELERY PETS' => 'Personal / Pet',
        'SP MEOWVO' => 'Personal / Pet',
        'KITTECUBE.COM' => 'Personal / Pet',
        'SP AUSSIEWOOF' => 'Personal / Pet',
        'SP RUFUS AND COCO' => 'Personal / Pet',
        'SP MICHU' => 'Personal / Pet',
        'GP VET' => 'Personal / Pet',
        'CAT SNACKS' => 'Personal / Pet',
        'HUBBL - BINGE' => 'Entertainment / Streaming',
        'Spotify' => 'Entertainment / Streaming',
        'AMZNPRIMEAU' => 'Entertainment / Streaming',
        'STEAM PURCHASE' => 'Entertainment / Gaming',
        'PAYPAL *TWITCHINTER' => 'Entertainment / Twitch',
        'PAYPAL *GISMART' => 'Office / Mobile App',
        'PAYPAL *IMPULSE' => 'Office / Mobile App',
        'Google XAPPIFY' => 'Office / Mobile App',
        'Google Pujie' => 'Office / Mobile App',
        'Google Hiya' => 'Office / Mobile App',
        'Google SYGIC' => 'Office / Mobile App',
        'Google OBD2 Car Scann' => 'Office / Mobile App',
        'FLEXJOBS' => 'Personal / Job Hunting',
        'REMOTEJOBS.IO' => 'Personal / Job Hunting',
        'jobleads.com' => 'Personal / Job Hunting',
        'SP THEPERFECTRESUME' => 'Personal / Job Hunting',
        'Upwork' => 'Personal / Job Hunting',
        'EQUIFAX' => 'Personal / Finance',
        'HEART RESEARCH' => 'Personal / Charity',
        'Credit Card Interest' => 'Personal / Finance / Bank Fees',
        'Purchase Interest' => 'Personal / Finance / Bank Fees',
        'Card Replacement Fee' => 'Personal / Finance / Bank Fees',
        'Insufficient funds' => 'Personal / Finance / Bank Fees',
        'LIBERTY HIGHGATE HILL' => 'Transport / Motorcycle / Fuel',
        'Reddy Express' => 'Transport / Motorcycle / Fuel',
        'LINKT' => 'Transport / Tolls',
        'READING NEWMARKET' => 'Entertainment / Event',
        'CELLOPARK' => 'Transport / Parking',
        'SQ *THE KEBAB SHOP' => 'Food / Quick Foods',
        'SUBWAY' => 'Food / Quick Foods',
        'EATCLUB' => 'Food / Restaurant',
        'SQ *MOTORCYCLE FREIGH' => 'Transport / Motorcycle',
        'ENGINEERING LEADERSHI' => 'Office / Newsletter',
        'PAYPAL *LUCENTGLOBE' => 'Food / Groceries',
        'ONLYFANS' => 'Entertainment / Adult',
        'Optmus to CC' => 'Transfer / Optimus to CC',
        'AUSSIE BROADBAND LIMI' => 'Bills / Internet',
        'LinkedIn' => 'Personal / Job Hunting',
        'Google Workspace' => 'Office / Online Service',
        'GSUITE' => 'Office / Online Service',
        'Google YouTube' => 'Entertainment / Streaming',
        'GOOGLE*YOUTUBE' => 'Entertainment / Streaming',
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

        foreach (Category::query()->with('parent.parent')->get() as $category) {
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
