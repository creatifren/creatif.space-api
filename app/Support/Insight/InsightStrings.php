<?php

namespace App\Support\Insight;

/**
 * Every word a report prints, in English and Indonesian. Kept here rather
 * than lang/ so the two columns can be read side by side and a missing key
 * fails the test suite, not a client's PDF.
 */
final class InsightStrings
{
    public const STRINGS = [
        'en' => [
            'report_title' => 'Social media report',
            'prepared_for' => 'Prepared for',
            'period' => 'Period',
            'summary' => 'Summary',
            'workflow' => 'Workflow',
            'channels' => 'Channels',
            'channel' => 'Channel',
            'top_posts' => 'Top posts',
            'next_month' => 'Notes for next month',
            'method' => 'How these numbers are made',
            'posts_published' => 'Posts published',
            'reach' => 'Reach',
            'impressions' => 'Impressions',
            'engagement' => 'Engagement',
            'engagement_rate' => 'Engagement rate',
            'followers' => 'Followers',
            'followers_change' => 'Follower change',
            'posts_approved' => 'Posts approved',
            'avg_approval_time' => 'Average approval time',
            'avg_approval_short' => 'Avg. approval :time',
            'headline_up' => ':metric up :pct vs :previous',
            'headline_down' => ':metric down :pct vs :previous',
            'headline_flat' => ':metric unchanged vs :previous',
            'previous_period' => 'the previous period',
            'footnotes' => 'Numbers shown as —',
            'channel_unavailable' => ':network did not share its numbers for this report.',
            'on_time_rate' => 'Published on time',
            'weekly_reach' => 'Reach per week',
            'week' => 'Wk',
            'vs_previous' => 'vs previous period',
            'more_channels' => '+:count more channels in the detailed report',
            'no_posts' => 'No published posts in this period.',
            'no_reach_chart' => 'Reach is not available for these channels.',
            'not_available' => 'Not available from :network API',
            'metric_not_available' => ':metric not available from :network API',
            'followers_no_history' => 'Follower history for :network starts :date.',
            'followers_no_history_none' => 'Follower history for :network has not been recorded yet.',
            'on_time_note' => 'On time = published within 10 minutes of the scheduled time, for scheduled posts whose publish time the network reports.',
            'approval_note' => 'Approval time = from the approval request (or the post being created) to the approval.',
            'engagement_note' => 'Engagement = likes + comments + shares + saves. Engagement rate = engagement ÷ reach, over posts that report reach.',
            'reach_note' => 'Reach and impressions are the numbers each network reports for the posts published in the period.',
            'followers_note' => 'Followers are read once a day; the change compares the first and last reading in the period.',
            'comparison_note' => 'Changes compare against the previous period of the same length, only where both periods have data.',
            'source_note' => 'Numbers come from each network\'s API at the time the report was made. Nothing is estimated.',
            'made_with' => 'Made with Creatif Space',
            'generated' => 'Generated :date',
            'likes' => 'Likes',
            'comments' => 'Comments',
            'shares' => 'Shares',
            'saves' => 'Saves',
            'views' => 'Views',
            'page' => 'Page',
            'start' => 'Start',
            'end' => 'End',
            'none' => 'None',
        ],
        'id' => [
            'report_title' => 'Laporan media sosial',
            'prepared_for' => 'Disiapkan untuk',
            'period' => 'Periode',
            'summary' => 'Ringkasan',
            'workflow' => 'Alur kerja',
            'channels' => 'Kanal',
            'channel' => 'Kanal',
            'top_posts' => 'Post teratas',
            'next_month' => 'Catatan untuk bulan depan',
            'method' => 'Cara angka ini dihitung',
            'posts_published' => 'Post terbit',
            'reach' => 'Jangkauan',
            'impressions' => 'Tayangan',
            'engagement' => 'Interaksi',
            'engagement_rate' => 'Tingkat interaksi',
            'followers' => 'Pengikut',
            'followers_change' => 'Perubahan pengikut',
            'posts_approved' => 'Post disetujui',
            'avg_approval_time' => 'Rata-rata waktu persetujuan',
            'avg_approval_short' => 'Rata-rata :time',
            'headline_up' => ':metric naik :pct dibanding :previous',
            'headline_down' => ':metric turun :pct dibanding :previous',
            'headline_flat' => ':metric sama dengan :previous',
            'previous_period' => 'periode sebelumnya',
            'footnotes' => 'Angka yang tampil sebagai —',
            'channel_unavailable' => ':network tidak membagikan angkanya untuk laporan ini.',
            'on_time_rate' => 'Terbit tepat waktu',
            'weekly_reach' => 'Jangkauan per minggu',
            'week' => 'Mg',
            'vs_previous' => 'dibanding periode sebelumnya',
            'more_channels' => '+:count kanal lain di laporan detail',
            'no_posts' => 'Belum ada post terbit di periode ini.',
            'no_reach_chart' => 'Jangkauan tidak tersedia untuk kanal ini.',
            'not_available' => 'Tidak tersedia dari API :network',
            'metric_not_available' => ':metric tidak tersedia dari API :network',
            'followers_no_history' => 'Riwayat pengikut :network dimulai :date.',
            'followers_no_history_none' => 'Riwayat pengikut :network belum tercatat.',
            'on_time_note' => 'Tepat waktu = terbit dalam 10 menit dari jadwal, untuk post terjadwal yang waktu terbitnya dilaporkan jaringan.',
            'approval_note' => 'Waktu persetujuan = dari permintaan persetujuan (atau saat post dibuat) sampai disetujui.',
            'engagement_note' => 'Interaksi = suka + komentar + bagikan + simpan. Tingkat interaksi = interaksi ÷ jangkauan, dari post yang melaporkan jangkauan.',
            'reach_note' => 'Jangkauan dan tayangan adalah angka dari setiap jaringan untuk post yang terbit di periode ini.',
            'followers_note' => 'Pengikut dibaca sekali sehari; perubahan membandingkan bacaan pertama dan terakhir di periode ini.',
            'comparison_note' => 'Perubahan dibandingkan dengan periode sebelumnya yang sama panjang, hanya jika kedua periode punya data.',
            'source_note' => 'Angka diambil dari API setiap jaringan saat laporan dibuat. Tidak ada yang diperkirakan.',
            'made_with' => 'Dibuat dengan Creatif Space',
            'generated' => 'Dibuat :date',
            'likes' => 'Suka',
            'comments' => 'Komentar',
            'shares' => 'Bagikan',
            'saves' => 'Simpan',
            'views' => 'Tayangan',
            'page' => 'Halaman',
            'start' => 'Awal',
            'end' => 'Akhir',
            'none' => 'Tidak ada',
        ],
    ];

    /** Network names as each network writes its own. */
    public const NETWORKS = [
        'instagram' => 'Instagram',
        'facebook' => 'Facebook',
        'x' => 'X',
        'tiktok' => 'TikTok',
        'linkedin' => 'LinkedIn',
        'threads' => 'Threads',
        'youtube' => 'YouTube',
        'pinterest' => 'Pinterest',
        'bluesky' => 'Bluesky',
    ];

    /**
     * @param  array<string, string|int>  $params
     */
    public static function get(string $locale, string $key, array $params = []): string
    {
        $text = self::STRINGS[$locale][$key] ?? self::STRINGS['en'][$key] ?? $key;

        foreach ($params as $name => $value) {
            $text = str_replace(':'.$name, (string) $value, $text);
        }

        return $text;
    }

    public static function network(string $platform): string
    {
        return self::NETWORKS[$platform] ?? ucfirst($platform);
    }
}
