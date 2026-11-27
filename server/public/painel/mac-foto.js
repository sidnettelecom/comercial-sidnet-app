/* MAC pela foto (pedido do ALEX, 2026-11-27).
   Nos campos de MAC dos equipamentos recolhidos aparece o botão "Foto": tira ou escolhe a foto da etiqueta e o
   sistema tenta achar o MAC sozinho, tudo no próprio navegador (a foto não vai para o servidor):
     1. código de barras / QR da etiqueta (leitor do Chrome; se o navegador não tiver, a biblioteca ZXing);
     2. se não achar, lê o texto da etiqueta (Tesseract, OCR).
   O MAC achado vai para o campo, marcado para conferir; quem lança confere e corrige antes de salvar.
   Também avisa quando o que foi digitado não parece um MAC (12 letras/números de 0-9 e A-F). */
(function(){
  var campos=[].slice.call(document.querySelectorAll('.rec-eqs input[name$="[mac]"]'));
  if(!campos.length)return;
  var BASE='/painel/mac/';

  /* ---------- MAC: limpar, validar, achar no texto ---------- */
  function limpo(s){return String(s||'').toUpperCase().replace(/[^0-9A-Z]/g,'')}
  function ehMac(s){var m=limpo(s);return /^[0-9A-F]{12}$/.test(m)&&!/^(.)\1{11}$/.test(m)}
  function bonito(m){return m.match(/../g).join(':')}
  /* O OCR troca letras parecidas: O->0, I/L->1 (só dentro de um pedaço que já parece MAC) */
  function corrige(t){return t.replace(/O/g,'0').replace(/[IL]/g,'1')}
  var RE=/(?<![0-9A-Z])(?:(?:[0-9A-FOIL]{2}[:\-. ]){5}[0-9A-FOIL]{2}|(?:[0-9A-FOIL]{4}\.){2}[0-9A-FOIL]{4}|[0-9A-FOIL]{12})(?![0-9A-Z])/g;
  /* devolve [{mac, nota}] na ordem de preferência: a linha que tem "MAC" primeiro */
  function achaMacs(texto,origem){
    var out=[],linhas=String(texto||'').toUpperCase().split(/[\r\n]+/);
    linhas.forEach(function(l,i){
      var perto=/M\s*A\s*C/.test(l)||(i>0&&/M\s*A\s*C\s*:?\s*$/.test(linhas[i-1]));
      var achou=l.match(RE)||[];
      achou.forEach(function(a){
        var m=limpo(corrige(a));
        if(!ehMac(m))return;
        if(!perto&&/^\d+$/.test(m))return; /* só número e longe da palavra MAC: deve ser serial */
        out.push({mac:m,nota:origem+(perto?' · ao lado de "MAC"':''),peso:perto?2:(/[A-F]/.test(m)&&/\d/.test(m)?1:0)});
      });
    });
    return out;
  }
  function junta(lista){
    var visto={},out=[];
    lista.sort(function(a,b){return b.peso-a.peso}).forEach(function(c){if(!visto[c.mac]){visto[c.mac]=1;out.push(c)}});
    return out;
  }

  /* ---------- carregar bibliotecas só quando precisa ---------- */
  var carregados={};
  function script(arq){
    if(!carregados[arq])carregados[arq]=new Promise(function(ok,erro){
      var s=document.createElement('script');s.src=BASE+arq;s.onload=ok;s.onerror=function(){delete carregados[arq];erro(new Error('não carregou '+arq))};
      document.head.appendChild(s);
    });
    return carregados[arq];
  }

  /* ---------- foto -> canvas ---------- */
  function abreImagem(arquivo){
    return new Promise(function(ok,erro){
      var url=URL.createObjectURL(arquivo),img=new Image();
      img.onload=function(){ok({img:img,url:url})};
      img.onerror=function(){URL.revokeObjectURL(url);erro(new Error('Não deu para abrir a foto.'))};
      img.src=url;
    });
  }
  function canvas(img,max,giro,cinza){
    var w=img.naturalWidth,h=img.naturalHeight,k=Math.min(1,max/Math.max(w,h));
    w=Math.round(w*k);h=Math.round(h*k);
    var c=document.createElement('canvas'),de=giro%180?[h,w]:[w,h];
    c.width=de[0];c.height=de[1];
    var x=c.getContext('2d',{willReadFrequently:true});
    x.translate(c.width/2,c.height/2);x.rotate(giro*Math.PI/180);x.drawImage(img,-w/2,-h/2,w,h);
    if(cinza){
      var d=x.getImageData(0,0,c.width,c.height),p=d.data;
      for(var i=0;i<p.length;i+=4){var v=p[i]*.299+p[i+1]*.587+p[i+2]*.114;p[i]=p[i+1]=p[i+2]=v}
      x.putImageData(d,0,0);
    }
    return c;
  }

  /* ---------- 1. código de barras / QR ---------- */
  function lerCodigos(img){
    var nativo=null;
    if('BarcodeDetector' in window){
      nativo=Promise.resolve(BarcodeDetector.getSupportedFormats?BarcodeDetector.getSupportedFormats():[])
        .then(function(f){return new BarcodeDetector(f&&f.length?{formats:f}:undefined).detect(img)})
        .then(function(r){return r.map(function(b){return b.rawValue})});
    }
    return (nativo||Promise.reject()).catch(function(){
      /* navegador sem leitor (iPhone, Windows): ZXing, que lê um código por vez. Etiqueta costuma ter vários
         códigos um embaixo do outro (SN, MAC...), então tenta a foto inteira e faixas dela. */
      return script('zxing.min.js').then(function(){
        var Z=window.ZXing,hints=new Map(),achados=[];
        hints.set(Z.DecodeHintType.TRY_HARDER,true);
        var r=new Z.MultiFormatReader();r.setHints(hints);
        var c=canvas(img,2400,0,false),W=c.width,H=c.height;
        var partes=[[0,0,1,1],[0,0,1,.5],[0,.5,1,.5],[0,0,1,.4],[0,.3,1,.4],[0,.6,1,.4],[0,0,.5,1],[.5,0,.5,1]];
        partes.some(function(p){
          var cc=document.createElement('canvas');cc.width=Math.round(p[2]*W);cc.height=Math.round(p[3]*H);
          cc.getContext('2d').drawImage(c,p[0]*W,p[1]*H,cc.width,cc.height,0,0,cc.width,cc.height);
          try{
            var bmp=new Z.BinaryBitmap(new Z.HybridBinarizer(new Z.HTMLCanvasElementLuminanceSource(cc)));
            var t=r.decode(bmp).getText();if(achados.indexOf(t)<0)achados.push(t);
          }catch(e){}
          r.reset();
          return achados.some(function(t){return achaMacs(t,'').length});
        });
        return achados;
      });
    }).catch(function(){return []});
  }

  /* ---------- 2. texto da etiqueta (OCR) ---------- */
  var ocr=null;
  function leitorTexto(){
    if(!ocr)ocr=script('tesseract.min.js').then(function(){
      return Tesseract.createWorker('eng',1,{workerPath:BASE+'worker.min.js',corePath:BASE,langPath:BASE.replace(/\/$/,''),workerBlobURL:false,gzip:true});
    }).catch(function(e){ocr=null;throw e});
    return ocr;
  }
  /* tentativas [giro, modo do OCR, maior lado em px], parando na primeira que achar MAC: texto espalhado (11) e
     bloco único (6, que se perde menos quando tem código de barras colado no texto, em tamanhos diferentes);
     depois a foto girada (etiqueta fotografada de lado ou de cabeça para baixo) */
  var TENTATIVAS=[[0,'11',1600],[0,'6',1600],[0,'6',1100],[0,'6',800],[90,'11',1600],[270,'11',1600],[90,'6',1100],[270,'6',1100],[180,'11',1600],[180,'6',1100]];
  function lerTexto(img,aviso){
    return leitorTexto().then(function(w){
      var fila=TENTATIVAS.slice(),achou=[];
      function proximo(){
        if(!fila.length||achou.length)return achou;
        var t=fila.shift();
        aviso(t[0]?'Não achei de pé, tentando a foto girada…':'Lendo o texto da etiqueta… (a primeira vez demora um pouco)');
        return w.setParameters({tessedit_pageseg_mode:t[1]}).then(function(){return w.recognize(canvas(img,t[2],t[0],true))}).then(function(r){
          achou=achou.concat(achaMacs(r.data.text,'texto da etiqueta'));
          return proximo();
        });
      }
      return proximo();
    });
  }

  /* ---------- tela ---------- */
  var css=document.createElement('style');
  css.textContent='.mac-cx{display:flex;gap:6px}.mac-cx input{flex:1;min-width:0}'+
    '.mac-bt{display:inline-flex;align-items:center;gap:4px;white-space:nowrap;padding:0 10px!important;font-weight:700}'+
    '.mac-bt svg{flex:none}.mac-pn{grid-column:1/-1;display:flex;gap:10px;align-items:flex-start;padding:10px;border:1px solid var(--line);border-radius:12px;background:var(--surface,#fff);font-size:13px;font-weight:400;color:var(--ink)}'+
    '.mac-pn img{width:96px;height:96px;object-fit:cover;border-radius:8px;flex:none;background:#eee}.mac-pn .mac-tx{display:grid;gap:6px;min-width:0}'+
    '.mac-pn .mac-op{display:flex;flex-wrap:wrap;gap:6px}.mac-pn .mac-op button{font-family:var(--mono);font-weight:700}.mac-pn .mac-op small{display:block;font-family:inherit;font-weight:400;font-size:11px;color:var(--muted)}'+
    '.mac-pn .mac-x{margin-left:auto;flex:none}'+
    '.mac-pn .mac-op button.pri small{color:inherit;opacity:.85}.rec-eqs{align-items:start}'+
    '@media (max-width:560px){.rec-eqs{grid-template-columns:1fr}}'+
    '.mac-aviso{font-size:12px;font-weight:600;color:#b45309}.mac-aviso.ok{color:var(--ok,#15803d)}'+
    'input.mac-conferir{border-color:#f59e0b!important;box-shadow:0 0 0 3px rgba(245,158,11,.25)}input.mac-ruim{border-color:#dc2626!important}';
  document.head.appendChild(css);
  var icone='<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/></svg>';

  function aviso(i){
    var a=i._macAviso;
    if(!a){a=document.createElement('span');a.className='mac-aviso';a.setAttribute('aria-live','polite');i.closest('label').appendChild(a);i._macAviso=a}
    return a;
  }
  function confere(i){
    var v=i.value.trim(),a=aviso(i);
    if(v===''||ehMac(v)){
      i.classList.remove('mac-ruim');
      if(i.classList.contains('mac-conferir')&&v!==''){a.className='mac-aviso';a.textContent='Lido da foto: confira com a etiqueta antes de salvar.'}
      else a.textContent='';
      return true;
    }
    i.classList.add('mac-ruim');a.className='mac-aviso';
    var n=limpo(v).length;
    a.textContent='Não parece um MAC: são 12 letras/números (0 a 9 e A a F). '+(n===12?'Tem letra que não existe em MAC (G a Z).':'Tem '+n+'.');
    return false;
  }

  campos.forEach(function(i){
    var label=i.closest('label'),cx=document.createElement('span');cx.className='mac-cx';
    i.parentNode.insertBefore(cx,i);cx.appendChild(i);
    var arq=document.createElement('input');arq.type='file';arq.accept='image/*';arq.hidden=true;arq.tabIndex=-1;
    var bt=document.createElement('button');bt.type='button';bt.className='mac-bt';bt.innerHTML=icone+'Foto';
    bt.title='Tirar ou escolher a foto da etiqueta: o MAC é preenchido sozinho';
    bt.setAttribute('aria-label','Ler o MAC pela foto da etiqueta');
    cx.appendChild(bt);cx.appendChild(arq);
    bt.addEventListener('click',function(){arq.value='';arq.click()});
    i.addEventListener('input',function(){i.classList.remove('mac-conferir');confere(i)});
    i.addEventListener('blur',function(){confere(i)});
    if(i.value)confere(i);
    arq.addEventListener('change',function(){if(arq.files&&arq.files[0])ler(i,label,arq.files[0])});
  });

  function ler(i,label,arquivo){
    if(i._macPn)i._macPn.remove();
    var pn=document.createElement('div');pn.className='mac-pn';pn.setAttribute('role','status');
    pn.innerHTML='<img alt="Foto da etiqueta"><div class="mac-tx"><b class="mac-st">Procurando o código de barras…</b><div class="mac-op"></div></div><button type="button" class="mac-x" aria-label="Fechar">✕</button>';
    label.insertAdjacentElement('afterend',pn);
    /* nas telas de 2 colunas (MAC | Modelo) o painel fica depois do modelo, ocupando a linha toda */
    var viz=pn.nextElementSibling;if(viz&&viz.tagName==='LABEL'&&!viz.querySelector('input[name$="[mac]"]'))viz.insertAdjacentElement('afterend',pn);
    i._macPn=pn;
    var st=pn.querySelector('.mac-st'),op=pn.querySelector('.mac-op'),foto=null;
    pn.querySelector('.mac-x').addEventListener('click',function(){pn.remove();i._macPn=null});
    function fim(){if(foto)setTimeout(function(){URL.revokeObjectURL(foto.url)},60000)}
    abreImagem(arquivo).then(function(f){
      foto=f;pn.querySelector('img').src=f.url;
      return lerCodigos(f.img).then(function(cods){
        var c=[];cods.forEach(function(t){c=c.concat(achaMacs(t,'código de barras').map(function(x){x.peso+=3;return x}))});
        if(c.length)return c;
        return lerTexto(f.img,function(t){st.textContent=t});
      });
    }).then(function(c){
      c=junta(c);
      if(!c.length){
        st.textContent='Não achei o MAC nesta foto. Tente de novo mais perto, com a etiqueta reta e sem reflexo, ou digite.';
        return;
      }
      function usa(m){
        i.value=m;i.classList.add('mac-conferir');confere(i);i.focus();
        [].forEach.call(op.children,function(b){b.classList.toggle('pri',b.dataset.mac===m)});
      }
      st.textContent=c.length===1?'Achei este MAC e coloquei no campo. Confira com a etiqueta:':'Achei mais de um. Coloquei o primeiro; toque no certo se não for ele:';
      c.slice(0,6).forEach(function(x){
        var b=document.createElement('button');b.type='button';b.dataset.mac=x.mac;
        b.innerHTML=bonito(x.mac)+'<small></small>';b.querySelector('small').textContent=x.nota;
        b.addEventListener('click',function(){usa(x.mac)});op.appendChild(b);
      });
      usa(c[0].mac);
    }).catch(function(e){
      st.textContent='Não consegui ler a foto ('+(e&&e.message||'erro')+'). Digite o MAC.';
    }).then(fim);
  }

  /* antes de salvar: se algum MAC não parece MAC, mostra e pede para clicar de novo (pode ser serial de propósito) */
  var form=campos[0].form;
  if(form)form.addEventListener('submit',function(e){
    var s=e.submitter;if(s&&/_mais/.test(s.value||''))return; /* "+ Adicionar equipamento" não salva */
    var ruins=campos.filter(function(i){return !confere(i)});
    if(ruins.length&&!form._macJaAvisou){
      e.preventDefault();form._macJaAvisou=true;
      ruins[0].focus();ruins[0].scrollIntoView({block:'center'});
      aviso(ruins[0]).textContent+=' Se estiver certo assim, clique em salvar de novo.';
    }
  });
})();
