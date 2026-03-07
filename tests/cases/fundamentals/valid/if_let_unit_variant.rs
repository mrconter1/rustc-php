// exit: 0
// stdout: yes
enum Flag {
    On,
    Off,
}

fn main() {
    let f = Flag::On;
    if let Flag::On = f {
        println!("yes");
    } else {
        println!("no");
    }
}
