var https = require('https');
var fs = require('fs');
var url = require('url');

var ssl_options = {
    //requestCert: true,
  hostname: 'ticket.grid.iu.edu',
  key: fs.readFileSync('/etc/grid-security/http/key.pem'),
  cert: fs.readFileSync('/etc/grid-security/http/cert.pem')
};
var app = https.createServer(ssl_options, handler).listen(12346);
var io = require('socket.io').listen(app);
io.set('log level', 2); //info
var lan_ip_prefix = "192.168.";

var acl = {}; //key:id, value:{cid,name}
var acl_timeout = 60*60*3; //3 hours long enough?
setInterval(function() {
    //remove old acl... timeout!
    var now = new Date();
    for(var key in acl) {
        var a = acl[key];
        if(now.getTime() - a.time.getTime() > 1000*acl_timeout) {
            console.log("acl timeout: "+key);
            delete acl[key];
        }
    }
}, 1000*60);//check every 60 seconds

function handler(req, res) {
    var remote_addr = req.connection.remoteAddress;
    var u = url.parse(req.url, true);
    if(u.pathname == "/") {
        fs.readFile(__dirname + '/chat.html', function (err, data) {
            if(err) {
              res.writeHead(500);
              return res.end('Error loading html');
            }
            res.writeHead(200);
            res.end(data);
        });
    } else if(u.pathname == "/ac") {
        if(remote_addr.indexOf(lan_ip_prefix) != 0) {
            console.log("non locahost request made for /ac ("+remote_addr+").. ignoring");
        } else {
            //console.log(remote_addr);
            //console.dir(u);
            var key = u.query.key;
            var cid = u.query.cid;
            var name = u.query.name;
            acl[key] = {cid: cid, name: name, time: new Date()}; 
            //console.dir(acl);
            console.log("registered:"+key+" name:"+name);
            res.writeHead(200);
            res.end('registered');
        }
    }
}

var clients = {}; //currently connected clients
var clients_len = 0;
//TODO - should I clean up every now and then?

io.sockets.on('connection', function (socket) {
    clients[socket.id] = socket;
    clients_len++;
    console.log("client connected:"+socket.id+" client num:"+clients_len);

    socket.on('disconnect', function() {
        console.log("client disconnected:"+socket.id);
        var disconnecting_client = clients[socket.id];
        delete clients[socket.id];
        clients_len--;
        
        //notify to all remaining users
        for(var pid in clients) {
            var peer = clients[pid];
            if(peer.ticketid == disconnecting_client.ticketid) {
                peer.emit('peer_disconnect', socket.id);
            }
        } 
    });

    socket.on('authenticate', function(info) {
        console.log("client sent us auth info");
        console.dir(info);
        
        client = clients[socket.id];
        //store ticket id associated with this connection
        client.ticketid = info.ticketid;
        client.ip = socket.handshake.address;

        //lookup nodekey and store user info (if available..)
        var a = acl[info.nodekey];
        if(a != undefined) {
            console.log("attached access registration for socket:"+socket.id);
            console.dir(a);
            client.acl = a;
        } else {
            console.log("failed to find acl for nodekey:"+info.nodekey+" - assuming guest");
            client.acl = {cid: undefined, name: "Guest", ip: client.ip};
        }

        //find current clients with the same ticket ids
        var peer_acls = {};
        for(var pid in clients) {
            var peer = clients[pid];
            if(peer.ticketid == info.ticketid) {
                peer_acls[pid] = peer.acl;
                if(socket.id != pid) {
                    //construct an object containing a new comer
                    var p = new Object();
                    p[socket.id] = client.acl;
                    peer.emit('peer_connected', p); //notify to all existing peers
                }
            }
        }
        socket.emit('peers', peer_acls); //send list of all peers to new comer
    });
    socket.on('submit', function() {
        console.log('ticket updated: ');
        client = clients[socket.id];
        console.log(client.ticketid);
        for(var pid in clients) {
            var peer = clients[pid];
            if(peer.ticketid == client.ticketid && client.id != pid) {
                peer.emit('submit');
            }
        }
    });
});
