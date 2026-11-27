/* Botão de olho nos campos de senha do painel: mostra ou esconde o que foi digitado. */
(function(){
  var olho='<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/><path class="risco" d="M4 4l16 16"/></svg>';
  document.querySelectorAll('input[type="password"]').forEach(function(i){
    var caixa=document.createElement('span');caixa.className='senha-box';
    i.parentNode.insertBefore(caixa,i);caixa.appendChild(i);
    var b=document.createElement('button');b.type='button';b.className='olho';
    b.setAttribute('aria-label','Mostrar senha');b.setAttribute('aria-pressed','false');b.innerHTML=olho;
    b.addEventListener('click',function(){
      var ver=i.type==='password';i.type=ver?'text':'password';
      b.setAttribute('aria-pressed',String(ver));b.setAttribute('aria-label',ver?'Esconder senha':'Mostrar senha');
    });
    caixa.appendChild(b);
    if(i.form)i.form.addEventListener('submit',function(){i.type='password'});
  });
})();

/* Comissões: avisa antes de trocar de vendedor ou de mês com alterações ainda não salvas. */
(function(){
  var f=document.querySelector('form.com');if(!f)return;
  var mudou=false;
  f.addEventListener('input',function(){mudou=true});f.addEventListener('change',function(){mudou=true});
  f.addEventListener('submit',function(){mudou=false});
  window.addEventListener('beforeunload',function(e){if(mudou){e.preventDefault();e.returnValue=''}});
})();

/* Listas que filtram ao escolher (vendedor na aba Vendas, mês em Vendas do mês).
   O painel bloqueia JavaScript escrito dentro do HTML (onchange=...), por isso o envio fica aqui. */
document.querySelectorAll('select[data-auto]').forEach(function(s){
  s.addEventListener('change',function(){if(s.form)s.form.submit()});
});

/* Recibo do RH: botão Imprimir (o painel bloqueia onclick escrito no HTML). */
document.querySelectorAll('[data-imprimir]').forEach(function(b){
  b.addEventListener('click',function(){window.print()});
});

/* Permissões: marcar o grupo marca tudo dentro dele; o grupo fica "meio marcado" quando só parte está. */
(function(){
  var cx=[].slice.call(document.querySelectorAll('.perm-arvore input[data-k]'));if(!cx.length)return;
  function dentro(k){return cx.filter(function(i){return i.dataset.k.indexOf(k+'.')===0})}
  function atualiza(){cx.forEach(function(i){var f=dentro(i.dataset.k).filter(function(x){return x.name});if(!f.length)return;
    var on=f.filter(function(x){return x.checked}).length;i.checked=on===f.length;i.indeterminate=on>0&&on<f.length})}
  cx.forEach(function(i){i.addEventListener('change',function(){dentro(i.dataset.k).forEach(function(x){x.checked=i.checked});atualiza()})});
  atualiza();
})();

/* Serviços em execução: mostra quantidade e valor por unidade, ou o valor fechado, e calcula o total na hora. */
(function(){
  var f=document.querySelector('[data-serv]');if(!f)return;
  var sel=f.querySelector('select[name="l[preco_id]"]'),q=f.querySelector('input[name="l[qtd]"]'),u=f.querySelector('input[name="l[unitario]"]'),
      v=f.querySelector('input[name="l[valor]"]'),tot=document.querySelector('[data-serv-total]');
  function num(s){s=String(s||'').replace(/[^\d,.]/g,'');if(s.indexOf(',')>=0)s=s.replace(/\./g,'').replace(',','.');else if(/^\d{1,3}(\.\d{3})+$/.test(s))s=s.replace(/\./g,'');return parseFloat(s)||0}
  function brl(n){return n.toLocaleString('pt-BR',{style:'currency',currency:'BRL'})}
  function atualiza(){
    var o=sel.options[sel.selectedIndex],fechado=sel.value==='0',un=o.getAttribute('data-un')||'',pv=parseFloat(o.getAttribute('data-valor')||'')||0;
    f.querySelectorAll('.so-qtd').forEach(function(l){l.hidden=fechado});
    f.querySelectorAll('.so-fechado').forEach(function(l){l.hidden=!fechado});
    f.querySelectorAll('.so-cabo').forEach(function(l){l.hidden=fechado||un.toLowerCase().indexOf('metro')!==0});
    q.required=!fechado;v.required=fechado;
    if(!fechado){f.querySelector('.so-qtd span').textContent='Valor por '+un+' R$';q.placeholder='quantos ('+un+')';u.placeholder=pv?pv.toFixed(2).replace('.',','):'0,00'}
    var t=fechado?num(v.value):num(q.value)*(num(u.value)||pv);
    if(tot)tot.innerHTML='Total: <b style="font-size:18px">'+brl(t)+'</b>';
  }
  f.addEventListener('input',atualiza);f.addEventListener('change',atualiza);atualiza();
})();
