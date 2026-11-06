const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('public/assets/admin/trip-count.js', 'utf8');
const flush = () => new Promise(resolve => setImmediate(resolve));

test('live count updates, retains last value on failures, pauses hidden tabs and resumes', async () => {
  const count = {textContent:'0'}, status = {textContent:''};
  const listeners = {}, timers = new Map(); let next = 0, calls = 0, value = 1, fail = false;
  const document = {hidden:false, getElementById:id=>id==='ongoing-trip-count'?count:status, addEventListener:(name,fn)=>listeners[name]=fn};
  vm.runInNewContext(source, {document, AbortController, Date, Number, String,
    setTimeout:(fn,delay)=>{const id=++next;timers.set(id,{fn,delay});return id;},
    clearTimeout:id=>timers.delete(id),
    fetch:async()=>{calls++;if(fail)throw new Error('offline');return {ok:true,redirected:false,json:async()=>({ongoing_trips:value})};}
  });
  await flush(); assert.equal(count.textContent,'1');
  assert.ok([...timers.values()].some(t=>t.delay===5000));
  value=2; [...timers.values()].find(t=>t.delay===5000).fn();await flush();assert.equal(count.textContent,'2');
  fail=true; [...timers.values()].find(t=>t.delay===5000).fn();await flush();
  assert.equal(count.textContent,'2');assert.match(status.textContent,/retrying/);
  document.hidden=true;listeners.visibilitychange();assert.equal(timers.size,0);
  const before=calls;document.hidden=false;fail=false;value=0;listeners.visibilitychange();await flush();
  assert.equal(calls,before+1);assert.equal(count.textContent,'0');
  value=-1;[...timers.values()].find(t=>t.delay===5000).fn();await flush();assert.equal(count.textContent,'0');
});
