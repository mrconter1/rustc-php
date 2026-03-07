// exit: 0
// stdout: one
// stdout: two
// stdout: other

fn main() {
    let a: Option<i32> = Option::<i32>::Some(1);
    let b: Option<i32> = Option::<i32>::Some(2);
    if let Option::<i32>::Some(n) = a {
        if n == 1 { println!("one"); } else if n == 2 { println!("two"); } else { println!("other"); }
    } else if let Option::<i32>::Some(n) = b {
        if n == 1 { println!("one"); } else if n == 2 { println!("two"); } else { println!("other"); }
    } else {
        println!("other");
    }
    let c: Option<i32> = Option::<i32>::None;
    if let Option::<i32>::Some(n) = c {
        if n == 1 { println!("one"); } else { println!("other"); }
    } else if let Option::<i32>::Some(n) = b {
        if n == 2 { println!("two"); } else { println!("other"); }
    } else {
        println!("other");
    }
    println!("other");
}
