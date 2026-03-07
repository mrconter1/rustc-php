// exit: 0
// stdout: two
// stdout: other

fn main() {
    let x: i32 = 2;
    match x {
        1 => { println!("one"); }
        2 => { println!("two"); }
        3 => { println!("three"); }
        _ => { println!("other"); }
    }
    let y: i32 = 99;
    match y {
        1 => { println!("one"); }
        2 => { println!("two"); }
        _ => { println!("other"); }
    }
}
