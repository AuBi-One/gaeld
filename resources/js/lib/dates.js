// Calendar dates as 'YYYY-MM-DD' strings. A date-only value is not an instant: going
// through toISOString() on a local Date shifts it by the UTC offset (a day early around
// midnight, and across a DST change), so these helpers stay in one frame each.

const pad = n => String(n).padStart(2, '0')

/** Today's date in the browser's timezone (not UTC). */
export function todayLocal(now = new Date()) {
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
}

/** A 'YYYY-MM-DD' date plus a number of days, computed in UTC so DST cannot move it. */
export function addDays(date, days) {
  const d = new Date(`${date}T00:00:00Z`)
  d.setUTCDate(d.getUTCDate() + Number(days))
  return d.toISOString().slice(0, 10)
}
